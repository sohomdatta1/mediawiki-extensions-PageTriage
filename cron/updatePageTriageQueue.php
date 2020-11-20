<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\Extension\PageTriage\ArticleMetadata;
use MediaWiki\MediaWikiServices;

/**
 * A maintenance script that updates expired page metadata
 */
class UpdatePageTriageQueue extends Maintenance {

	/**
	 * @var \Wikimedia\Rdbms\IDatabase
	 */
	protected $dbr, $dbw;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Remove reviewed pages from pagetriage queue if they"
			. " are older then 30 days" );
		$this->requireExtension( 'PageTriage' );
		$this->setBatchSize( 100 );
	}

	protected function init() {
		$this->dbr = wfGetDB( DB_REPLICA );
		$this->dbw = wfGetDB( DB_MASTER );
	}

	/**
	 * @suppress PhanPossiblyUndeclaredVariable False positive with $row
	 */
	public function execute() {
		global $wgPageTriageNamespaces;
		// This list doesn't include Article or Draft
		// because they have special handling.
		$secondaryNamespaces = array_filter(
			$wgPageTriageNamespaces,
			function ( $ns ) {
				return $ns !== 0;
			}
		);
		$this->init();
		$this->output( "Started processing... \n" );

		// Scan for data with ptrp_created set more than 30 days ago
		$startTime = (int)wfTimestamp( TS_UNIX ) - 30 * 60 * 60 * 24;
		$count = $this->getBatchSize();

		$idRow = $this->dbr->selectRow(
			[ 'pagetriage_page' ],
			[ 'MAX(ptrp_page_id) AS max_id' ],
			[],
			__METHOD__
		);

		// No data to process, exit
		if ( $idRow === false ) {
			$this->output( "No data to process \n" );
			return;
		}

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$startId = $idRow->max_id + 1;

		while ( $count === $this->getBatchSize() ) {
			$count = 0;
			$startTime = $this->dbr->addQuotes( $this->dbr->timestamp( $startTime ) );

			// Remove pages older than 30 days, if
			// 1. the page is in the article namespace and has been reviewed, or
			// 2. the page is not in main or draft namespaces or
			// 3. the page is a redirect
			$res = $this->dbr->select(
				[ 'pagetriage_page', 'page' ],
				[ 'ptrp_page_id', 'ptrp_created', 'page_namespace', 'ptrp_reviewed' ],
				[
					'(ptrp_created < ' . $startTime . ') OR
					(ptrp_created = ' . $startTime . ' AND ptrp_page_id < ' . (int)$startId . ')',
					$this->dbr->makeList( [
						$this->dbr->makeList( [
							'page_namespace' => 0,
							'ptrp_reviewed > 0'
						], LIST_AND ),
						'page_namespace' => $secondaryNamespaces,
						'page_is_redirect' => 1,
					], LIST_OR ),
				],
				__METHOD__,
				[ 'LIMIT' => $this->getBatchSize(), 'ORDER BY' => 'ptrp_created DESC, ptrp_page_id DESC' ],
				[ 'page' => [ 'INNER JOIN', 'ptrp_page_id = page_id' ] ]
			);

			$pageId = [];
			foreach ( $res as $row ) {
				$pageId[] = $row->ptrp_page_id;
				$count++;
			}

			if ( $pageId ) {
				// update data from last row
				if ( $row->ptrp_created ) {
					$startTime = wfTimestamp( TS_UNIX, $row->ptrp_created );
				}
				$startId = $row->ptrp_page_id;

				$this->beginTransaction( $this->dbw, __METHOD__ );

				$this->dbw->delete(
						'pagetriage_page',
						[ 'ptrp_page_id' => $pageId ],
						__METHOD__
				);
				$articleMetadata = new ArticleMetadata( $pageId );
				$articleMetadata->deleteMetadata();

				$this->commitTransaction( $this->dbw, __METHOD__ );
			}

			$this->output( "processed $count \n" );
			$lbFactory->waitForReplication();
		}

		// Also clean-up old logging data while we're at it.
		$yearago = (int)wfTimestamp( TS_UNIX ) - 365 * 60 * 60 * 24;
		$yearago = $this->dbr->addQuotes( $this->dbr->timestamp( $yearago ) );
		$this->dbw->delete(
			'pagetriage_log',
			[ 'ptrl_timestamp < ' . $yearago ],
			__METHOD__
		);

		$this->output( "Completed \n" );
	}
}

$maintClass = UpdatePageTriageQueue::class;
require_once RUN_MAINTENANCE_IF_MAIN;
