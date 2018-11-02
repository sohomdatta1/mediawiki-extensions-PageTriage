<?php

namespace MediaWiki\Extension\PageTriage;

use ApiBase;
use Article;
use Block;
use MediaWiki\Extension\PageTriage\Api\ApiPageTriageList;
use EchoEvent;
use Exception;
use ExtensionRegistry;
use MediaWiki\MediaWikiServices;
use ORES\Hooks\Helpers;
use RequestContext;
use Title;
use User;
use WikiPage;

/**
 * Utility class for PageTriage
 */
class PageTriageUtil {

	/**
	 * Get whether or not a page needs triaging
	 *
	 * @param WikiPage|Article|int $article
	 *
	 * @throws Exception
	 * @return Mixed null if the page is not in the triage system,
	 * otherwise whether or not the page is unreviewed.
	 * Return convention is this way so that null and false are equivalent
	 * with a straight boolean test.
	 */
	public static function doesPageNeedTriage( $article ) {
		if ( ! $article ) {
			throw new Exception( "Invalid argument to " . __METHOD__ );
		}

		if ( $article instanceof WikiPage || $article instanceof Article ) {
			$pageId = $article->getId();
		} elseif ( is_numeric( $article ) ) {
			$pageId = $article;
		} else {
			return null;
		}

		$dbr = wfGetDB( DB_REPLICA );

		$row = $dbr->selectRow( 'pagetriage_page', 'ptrp_reviewed',
			[ 'ptrp_page_id' => $pageId ]
		);

		if ( ! $row ) {
			return null;
		}

		return !(bool)$row->ptrp_reviewed;
	}

	/**
	 * Get the IDs of applicable PageTriage namespaces.
	 * @return int[]
	 */
	public static function getNamespaces() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$pageTriageDraftNamespaceId = $config->get( 'PageTriageDraftNamespaceId' );
		$pageTriageNamespaces = $config->get( 'PageTriageNamespaces' );
		// Add the Draft namespace if configured.
		if ( $pageTriageDraftNamespaceId
			&& !in_array( $pageTriageDraftNamespaceId, $pageTriageNamespaces )
		) {
			$pageTriageNamespaces[] = $pageTriageDraftNamespaceId;
		}
		return $pageTriageNamespaces;
	}

	/**
	 * Validate a page namespace ID.
	 * @param int $namespace The namespace ID to validate.
	 * @return int The provided namespace if valid, otherwise 0 (main namespace).
	 */
	public static function validatePageNamespace( $namespace ) {
		$pageTriageNamespaces = static::getNamespaces();
		if ( !in_array( $namespace, $pageTriageNamespaces ) ) {
			$namespace = NS_MAIN;
		}

		return (int)$namespace;
	}

	/**
	 * Get a list of stat for unreviewed articles
	 * @param int $namespace
	 * @return array
	 *
	 * @todo - Limit the number of records by a timestamp filter, maybe 30 days etc,
	 *         depends on the time the triage queue should look back for listview
	 */
	public static function getUnreviewedArticleStat( $namespace = '' ) {
		global $wgMemc;

		$namespace = self::validatePageNamespace( $namespace );

		$key = wfMemcKey(
			'pagetriage',
			'unreviewed-article-' . $namespace,
			'stat', self::getCacheVersion()
		);

		$data = $wgMemc->get( $key );
		if ( $data !== false ) {
			return $data;
		}

		$dbr = wfGetDB( DB_REPLICA );

		$table = [ 'pagetriage_page', 'page' ];
		$conds = [
			'ptrp_reviewed' => 0,
			'page_id = ptrp_page_id',
			'page_is_redirect' => 0, // remove redirect from the unreviewd number per bug40540
			'page_namespace' => $namespace
		];

		$res = $dbr->selectRow(
			$table,
			[ 'COUNT(ptrp_page_id) AS total', 'MIN(ptrp_created) AS oldest' ],
			$conds
		);

		$data = [ 'count' => 0, 'oldest' => '' ];

		if ( $res ) {
			$data['count'] = (int)$res->total;
			$data['oldest'] = $res->oldest;
		}

		// make it expire in 10 minutes
		$wgMemc->set( $key, $data, 600 );
		return $data;
	}

	/**
	 * Get the number of pages based on the selected filters.
	 * @param array $filters Associative array of filter names/values.
	 *                       See ApiPageTriageStats->getAllowedParams() for possible values,
	 *                       which are the same that the ApiPageTriageList endpoint accepts.
	 * @return int Number of pages based on the selected filters
	 */
	public static function getArticleFilterStat( $filters ) {
		if ( !isset( $filters['showreviewed'] ) && !isset( $filters['showunreviewed'] ) ) {
			$filters['showunreviewed'] = 'showunreviewed';
		}

		return ApiPageTriageList::getPageIds( $filters, true );
	}

	/**
	 * Get number of reviewed articles in the past week
	 * @param string|int $namespace Namespace number
	 * @return array Stats to be returned
	 */
	public static function getReviewedArticleStat( $namespace = '' ) {
		global $wgMemc;

		$namespace = self::validatePageNamespace( $namespace );

		$key = wfMemcKey(
			'pagetriage',
			'reviewed-article-' . $namespace,
			'stat',
			self::getCacheVersion()
		);

		$data = $wgMemc->get( $key );
		if ( $data !== false ) {
			return $data;
		}

		$time = wfTimestamp( TS_UNIX ) - 7 * 24 * 60 * 60;

		$dbr = wfGetDB( DB_REPLICA );

		$table = [ 'pagetriage_page', 'page' ];
		$conds = [
			'ptrp_reviewed' => 1,
			'page_id = ptrp_page_id',
			'page_namespace' => $namespace,
			'ptrp_reviewed_updated > ' . $dbr->addQuotes( $dbr->timestamp( $time ) )
		];

		$res = $dbr->selectRow(
			$table,
			[ 'COUNT(ptrp_page_id) AS reviewed_count' ],
			$conds
		);

		$data = [ 'reviewed_count' => 0 ];

		if ( $res ) {
			$data['reviewed_count'] = (int)$res->reviewed_count;
		}

		// make it expire in 10 minutes
		$wgMemc->set( $key, $data, 600 );

		return $data;
	}

	/**
	 * Get top page triagers in various time frame
	 * @param string $time time to look back for top triagers, possible values include
	 *                     last-day, last-week, last-month
	 * @return array
	 */
	public static function getTopTriagers( $time = 'last-week' ) {
		global $wgMemc;

		$now = wfTimestamp( TS_UNIX );

		// times to look back for top trigers and expiration time in cache
		$timeFrame = [
				'last-day' => [ 'ts' => $now - 24 * 60 * 60, 'expire' => 60 * 60 ],
				'last-week' => [ 'ts' => $now - 7 * 24 * 60 * 60, 'expire' => 24 * 60 * 60 ],
				'last-month' => [ 'ts' => $now - 30 * 24 * 60 * 60, 'expire' => 24 * 60 * 60 ],
		];

		if ( !isset( $timeFrame[$time] ) ) {
			$time = 'last-day';
		}

		$dbr = wfGetDB( DB_REPLICA );
		$key = wfMemcKey( 'pagetriage', 'top-triager', $time, self::getCacheVersion() );

		$topTriager = $wgMemc->get( $key );
		if ( $topTriager === false ) {
			$res = $dbr->select(
				[ 'pagetriage_log', 'user' ],
				[ 'user_name', 'user_id', 'COUNT(ptrl_id) AS num' ],
				[
					'user_id = ptrl_user_id',
					'ptrl_reviewed' => 1, // only reviewed status
					'ptrl_timestamp > ' . $dbr->addQuotes( $dbr->timestamp( $timeFrame[$time]['ts'] ) )
				],
				__METHOD__,
				[ 'GROUP BY' => 'user_id', 'ORDER BY' => 'num DESC', 'LIMIT' => 50 ]
			);

			$topTriager = iterator_to_array( $res );

			$wgMemc->set( $key, $topTriager, $timeFrame[$time]['expire'] );
		}

		return $topTriager;
	}

	/**
	 * returns the cache key for user status
	 * @param string $userName
	 * @return string
	 */
	public static function userStatusKey( $userName ) {
		return wfMemcKey( 'pagetriage', 'user-page-status', sha1( $userName ), self::getCacheVersion() );
	}

	/**
	 * Check the existance of user page and talk page for a list of users
	 * @param array $users contains user_name db keys
	 * @return array
	 */
	public static function pageStatusForUser( $users ) {
		global $wgMemc;

		$return = [];
		$title  = [];

		foreach ( $users as $user ) {
			$user = (array)$user;
			$searchKey = [ 'user_name', 'reviewer' ];

			foreach ( $searchKey as $val ) {
				if ( !isset( $user[$val] ) || !$user[$val] ) {
					continue;
				}
				$data = $wgMemc->get( self::userStatusKey( $user[$val] ) );
				// data is in memcache
				if ( $data !== false ) {
					foreach ( $data as $pageKey => $status ) {
						if ( $status === 1 ) {
							$return[$pageKey] = $status;
						}
					}
				// data is not in memcache and will be checked against database
				} else {
					$u = Title::newFromText( $user[$val], NS_USER );
					if ( $u ) {
						if ( isset( $title[$u->getDBkey()] ) ) {
							continue;
						}
						$t = Title::makeTitle( NS_USER_TALK, $u->getDBkey() );
						// store the data in $title, 'u' is for user page, 't' is for talk page
						$title[$u->getDBkey()] = [ 'user_name' => $user[$val], 'u' => $u, 't' => $t ];
					}
				}
			}
		}

		if ( $title ) {
			$dbr = wfGetDB( DB_REPLICA );
			$res = $dbr->select(
				[ 'page' ],
				[ 'page_namespace', 'page_title' ],
				[
					'page_title' => array_keys( $title ),
					'page_namespace' => [ NS_USER, NS_USER_TALK ]
				],
				__METHOD__
			);

			$dataToCache = [];
			// if there is result from the database, that means the page exists, set it to the
			// cache array with value 1
			foreach ( $res as $row ) {
				$user = $title[$row->page_title];
				if ( $row->page_namespace == NS_USER ) {
					$dataToCache[$user['user_name']][$user['u']->getPrefixedDBkey()] = 1;
				} else {
					$dataToCache[$user['user_name']][$user['t']->getPrefixedDBkey()] = 1;
				}
			}
			// Loop through the original $title array, set the data not in db result with value 0
			// then save the cache value to memcache for next time use
			foreach ( $title as $key => $value ) {
				if ( !isset( $dataToCache[$value['user_name']][$value['u']->getPrefixedDBkey()] ) ) {
					$dataToCache[$value['user_name']][$value['u']->getPrefixedDBkey()] = 0;
				} else {
					$return[$value['u']->getPrefixedDBkey()] = 1;
				}
				if ( !isset( $dataToCache[$value['user_name']][$value['t']->getPrefixedDBkey()] ) ) {
					$dataToCache[$value['user_name']][$value['t']->getPrefixedDBkey()] = 0;
				} else {
					$return[$value['t']->getPrefixedDBkey()] = 1;
				}
				$wgMemc->set(
					self::userStatusKey( $value['user_name'] ),
					$dataToCache[$value['user_name']],
					3600
				);
			}
		}

		return $return;
	}

	/**
	 * Update user metadata when a user's block status is updated
	 * @param Block $block Block object
	 * @param int $status 1/0
	 */
	public static function updateMetadataOnBlockChange( $block, $status = 1 ) {
		// do instant update if the number of page to be updated is less or equal to
		// the number below, otherwise, delay this to the cron
		$maxNumToProcess = 500;

		$tags = ArticleMetadata::getValidTags();
		if ( !$tags ) {
			return;
		}

		$dbr = wfGetDB( DB_REPLICA );

		$res = $dbr->select(
			[ 'pagetriage_page_tags' ],
			[ 'ptrpt_page_id' ],
			[ 'ptrpt_tag_id' => $tags['user_name'], 'ptrpt_value' => (string)$block->getTarget() ],
			__METHOD__,
			[ 'LIMIT' => $maxNumToProcess + 1 ]
		);

		if ( $dbr->numRows( $res ) > $maxNumToProcess ) {
			return;
		}

		$pageIds = [];
		foreach ( $res as $row ) {
			$pageIds[] = $row->ptrpt_page_id;
		}

		if ( !$pageIds ) {
			return;
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->startAtomic( __METHOD__ );
		$dbw->update(
			'pagetriage_page_tags',
			[ 'ptrpt_value' => $status ],
			[ 'ptrpt_page_id' => $pageIds, 'ptrpt_tag_id' => $tags['user_block_status'] ]
		);
		PageTriage::bulkSetTagsUpdated( $pageIds );
		$dbw->endAtomic( __METHOD__ );

		$metadata = new ArticleMetadata( $pageIds );
		$metadata->flushMetadataFromCache();
	}

	private static function getCacheVersion() {
		global $wgPageTriageCacheVersion;
		return $wgPageTriageCacheVersion;
	}

	/**
	 * Attempt to create an Echo notification event for
	 * 1. 'Mark as Reviewed' curation flyout
	 * 2. 'Mark as Patrolled' from Special:NewPages
	 * 3. 'Add maintenance tag' curation flyout
	 * 4. 'Add deletion tag' curation flyout
	 *
	 * @param Article $article
	 * @param User $user
	 * @param string $type notification type
	 * @param array|null $extra
	 */
	public static function createNotificationEvent( $article, $user, $type, $extra = null ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			return;
		}

		$params = [
			'type' => $type,
			'title' => $article->getTitle(),
			'agent' => $user,
		];

		if ( $extra ) {
			$extra['note'] = self::truncateLongText( $extra['note'] );
			$params['extra'] = $extra;
		}

		EchoEvent::create( $params );
	}

	/**
	 * @param string $text The text to truncate.
	 * @param int $length Maximum number of characters.
	 * @param string $ellipsis String to append to the end of truncated text.
	 * @return string
	 */
	public static function truncateLongText( $text, $length = 150, $ellipsis = '...' ) {
		if ( !is_string( $text ) ) {
			return $text;
		}

		return RequestContext::getMain()->getLanguage()->truncateForVisual( $text, $length, $ellipsis );
	}

	/**
	 * Get an array of ORES articlequality API parameters.
	 *
	 * @return array
	 */
	private static function getOresArticleQualityApiParams() {
		return [
			'show_predicted_class_stub' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
			'show_predicted_class_start' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
			'show_predicted_class_c' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
			'show_predicted_class_b' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
			'show_predicted_class_good' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
			'show_predicted_class_featured' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
		];
	}

	/**
	 * Get an array of ORES draftquality API parameters.
	 *
	 * @return array
	 */
	private static function getOresDraftQualityApiParams() {
		return [
			'show_predicted_issues_vandalism' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
			'show_predicted_issues_spam' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
			'show_predicted_issues_attack' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
			'show_predicted_issues_none' => [
				ApiBase::PARAM_TYPE => 'boolean'
			],
		];
	}

	/**
	 * Get an array of ORES API parameters.
	 *
	 * These are used in both NPP and AFC contexts.
	 *
	 * @return array
	 */
	public static function getOresApiParams() {
		return self::getOresArticleQualityApiParams() + self::getOresDraftQualityApiParams();
	}

	/**
	 * Get array of common API parameters, for use with getAllowedParams().
	 *
	 * @return array
	 */
	public static function getCommonApiParams() {
		return [
			'showbots' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'showredirs' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'showothers' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'showreviewed' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'showunreviewed' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'showdeleted' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'namespace' => [
				ApiBase::PARAM_TYPE => 'integer',
			],
			'afc_state' => [
				ApiBase::PARAM_TYPE => 'integer',
			],
			'no_category' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'no_inbound_links' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'non_autoconfirmed_users' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'learners' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'blocked_users' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'username' => [
				ApiBase::PARAM_TYPE => 'user',
			],
		];
	}

	/**
	 * Helper method to check if the API call includes ORES articlequality parameters.
	 *
	 * @param array $opts
	 * @return bool
	 */
	public static function isOresArticleQualityQuery( $opts ) {
		return self::queryContains( $opts, self::getOresArticleQualityApiParams() );
	}

	/**
	 * Helper method to check if the API call includes ORES draftquality parameters.
	 *
	 * @param array $opts
	 * @return bool
	 */
	public static function isOresDraftQualityQuery( $opts ) {
		return self::queryContains( $opts, self::getOresDraftQualityApiParams() );
	}

	/**
	 * Helper method to check if $opts contains some of the parameters in $params.
	 *
	 * @param array $opts Selected parameters from API request
	 * @param array $params
	 * @return bool
	 */
	private static function queryContains( $opts, $params ) {
		$params = array_keys( $params );
		foreach ( $params as $key ) {
			if ( isset( $opts[ $key ] ) && $opts[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert ORES param names to class names.
	 *
	 * @param string $model Which model to convert names for ('articlequality' or 'draftquality')
	 * @param array $opts Selected parameters
	 * @return array Corresponding ORES class names
	 */
	public static function mapOresParamsToClassNames( $model, $opts ) {
		$paramsToClassesMap = [
			'articlequality' => [
				'show_predicted_class_stub' => 'Stub',
				'show_predicted_class_start' => 'Start',
				'show_predicted_class_c' => 'C',
				'show_predicted_class_b' => 'B',
				'show_predicted_class_good' => 'GA',
				'show_predicted_class_featured' => 'FA',
			],
			'draftquality' => [
				'show_predicted_issues_vandalism' => 'vandalism',
				'show_predicted_issues_spam' => 'spam',
				'show_predicted_issues_attack' => 'attack',
				'show_predicted_issues_none' => 'OK',
			],
		];
		$result = [];
		foreach ( $paramsToClassesMap[ $model ] as $param => $className ) {
			if ( isset( $opts[ $param ] ) && $opts[ $param ] ) {
				$result[] = $className;
			}
		}
		return $result;
	}

	/**
	 * Check if the ORES extension is present and configured
	 * correctly for PageTriage to integrate with it.
	 *
	 * @return bool
	 */
	public static function oresIsAvailable() {
		return ExtensionRegistry::getInstance()->isLoaded( 'ORES' ) &&
			Helpers::isModelEnabled( 'articlequality' ) &&
			Helpers::isModelEnabled( 'draftquality' );
	}

	/**
	 * @return array The copyvio filter parameter
	 */
	public static function getCopyvioApiParam() {
		return [
			'show_predicted_issues_copyvio' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
		];
	}

	/**
	 * Check if $opts contain the copyvio filter parameter
	 *
	 * @param array $opts
	 * @return bool
	 */
	public static function isCopyvioQuery( $opts ) {
		return $opts[ 'show_predicted_issues_copyvio' ] ?? false;
	}

}
