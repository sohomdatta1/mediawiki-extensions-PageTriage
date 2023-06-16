<?php

namespace MediaWiki\Extension\PageTriage\Test\Integration;

use MediaWiki\Extension\PageTriage\QueueLookup;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\PageTriage\QueueLookup
 * @group Database
 */
class QueueLookupTest extends MediaWikiIntegrationTestCase {

	/** @inheritDoc */
	protected $tablesUsed = [ 'pagetriage_page' ];

	public function setUp(): void {
		parent::setUp();
		// Clear queue entries that were automatically added on page creation in parent::setUp().
		$this->db->truncate( 'pagetriage_page' );
	}

	public function testGetByPageId() {
		$page = $this->insertPage( 'QueueLookupTest', 'Testing 1, 2' );
		$fetchedPage = $this->getQueueLookup()->getByPageId( $page[ 'id' ] );
		$this->assertSame( $page[ 'id' ], $fetchedPage->getPageId() );
	}

	public function testGetByPageIdReturnNull() {
		// Fetching an inexistent ID
		$fetchedPage = $this->getQueueLookup()->getByPageId( 24601 );
		$this->assertNull( $fetchedPage );
	}

	private function getQueueLookup(): QueueLookup {
		return new QueueLookup(
			$this->getServiceContainer()->getDBLoadBalancerFactory()
		);
	}

}
