<?php

namespace MediaWiki\Extension\PageTriage\Maintenance;

use BatchRowIterator;
use Maintenance;
use MediaWiki\Extension\PageTriage\ArticleCompile\ArticleCompileDeletionTag;
use MediaWiki\Extension\PageTriage\PageTriageUtil;

class FixNominatedForDeletion extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Set ptrp_deleted on pages nominated for deletion' );
		$this->addOption( 'dry-run', 'Do not fetch scores, only print revisions.' );
		$this->setBatchSize( 100 );
		$this->requireExtension( 'PageTriage' );
	}

	public function execute() {
		$dbr = PageTriageUtil::getReplicaConnection();
		$dbw = PageTriageUtil::getPrimaryConnection();

		$iterator = new BatchRowIterator(
			$dbr,
			[ 'pagetriage_page', 'categorylinks' ],
			'ptrp_page_id',
			$this->mBatchSize
		);
		$iterator->setFetchColumns( [ 'ptrp_page_id' ] );
		$iterator->addJoinConditions( [
			'categorylinks' => [ 'INNER JOIN', 'ptrp_page_id = cl_from' ],
		] );
		$iterator->addConditions( [
			'ptrp_deleted' => 0,
			'cl_to' => array_map( 'strval', array_keys( ArticleCompileDeletionTag::getDeletionTags() ) ),
		] );
		// deduplicate pages in multiples deletion categories
		$iterator->addOptions( [ 'GROUP BY' => 'ptrp_page_id' ] );
		$iterator->setCaller( __METHOD__ );

		foreach ( $iterator as $rows ) {
			$pageIds = array_map( static function ( $row ) {
				return $row->ptrp_page_id;
			}, $rows );

			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output( "Pages: " . implode( ', ', $pageIds ) . "\n" );
				continue;
			}

			$dbw->newUpdateQueryBuilder()
				->update( 'pagetriage_page' )
				->set( [ 'ptrp_deleted' => 1 ] )
				->where( [ 'ptrp_page_id' => $pageIds ] )
				->caller( __METHOD__ )
				->execute();
			$this->waitForReplication();

			$count = count( $pageIds );
			$first = reset( $pageIds );
			$last = end( $pageIds );
			$this->output( "Updated $count pages. From $first to $last.\n" );
		}

		$this->output( "Done\n" );
	}
}
