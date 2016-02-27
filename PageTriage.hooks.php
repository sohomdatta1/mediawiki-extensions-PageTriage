<?php

class PageTriageHooks {

	/**
	 * Mark a page as unreviewed after moving the page from non-main(article) namespace to
	 * main(article) namespace
	 *
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/SpecialMovepageAfterMove
	 * @param $movePage MovePageForm object
	 * @param $oldTitle Title old title object
	 * @param $newTitle Title new title object
	 * @return bool
	 */
	public static function onSpecialMovepageAfterMove( $movePage, &$oldTitle, &$newTitle ) {
		$pageId = $newTitle->getArticleID();

		// Delete cache for record if it's in pagetriage queue
		$articleMetadata = new ArticleMetadata( array( $pageId ) );
		$articleMetadata->flushMetadataFromCache();

		// Delete user status cache
		self::flushUserStatusCache( $oldTitle );
		self::flushUserStatusCache( $newTitle );

		$oldNamespace = $oldTitle->getNamespace();
		$newNamespace = $newTitle->getNamespace();
		// Do nothing further on if
		// 1. the page move is within the same namespace or
		// 2. the new page is not in article (main) namespace
		if ( $oldNamespace === $newNamespace || $newNamespace !== NS_MAIN ) {
			return true;
		}

		global $wgUser;
		// New record to pagetriage queue, compile metadata
		if ( self::addToPageTriageQueue( $pageId, $newTitle, $wgUser ) ) {
			$acp = ArticleCompileProcessor::newFromPageId( array( $pageId ) );
			if ( $acp ) {
				// safe to use slave db for data compilation for the
				// following components, BasicData is accessing pagetriage_page,
				// which is not safe to use slave db
				$config = array(
						'LinkCount' => DB_SLAVE,
						'CategoryCount' => DB_SLAVE,
						'Snippet' => DB_SLAVE,
						'UserData' => DB_SLAVE,
						'DeletionTag' => DB_SLAVE
				);
				$acp->configComponentDb( $config );
				$acp->compileMetadata();
			}
		}

		return true;
	}

	/**
	 * Check if a page is created from a redirect page, then insert into it PageTriage Queue
	 * Note: Page will be automatically marked as triaged for users with autopatrol right
	 *
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/NewRevisionFromEditComplete
	 * @param $page WikiPage the WikiPage edited
	 * @param $rev Revision|null the new revision
	 * @param $baseID int the revision ID this was based on, if any
	 * @param $user User the editing user
	 * @return bool
	 */
	public static function onNewRevisionFromEditComplete( $page, $rev, $baseID, $user ) {
		global $wgPageTriageNamespaces;

		if ( !in_array( $page->getTitle()->getNamespace(), $wgPageTriageNamespaces ) ) {
			return true;
		}

		if ( $rev && $rev->getParentId() ) {
			// Make sure $prev->getContent() is done post-send if possible
			DeferredUpdates::addCallableUpdate( function() use ( $rev, $page, $user ) {
				$prev = $rev->getPrevious();
				if ( $prev && !$page->isRedirect() && $prev->getContent()->isRedirect() ) {
					PageTriageHooks::addToPageTriageQueue(
						$page->getId(), $page->getTitle(), $user );
				}
			} );
		}

		return true;
	}

	/**
	 * When a new article is created, insert it into the PageTriage Queue
	 *
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleInsertComplete
	 * @param $article WikiPage created
	 * @param $user User creating the article
	 * @param $text string New content
	 * @param $summary string Edit summary/comment
	 * @param $isMinor bool Whether or not the edit was marked as minor
	 * @param $isWatch bool (No longer used)
	 * @param $section bool (No longer used)
	 * @param $flags: Flags passed to Article::doEdit()
	 * @param $revision Revision New Revision of the article
	 * @return bool
	 */
	public static function onArticleInsertComplete(
		$article, $user, $text, $summary, $isMinor, $isWatch, $section, $flags, $revision
	) {
		global $wgPageTriageNamespaces;
		if ( !in_array( $article->getTitle()->getNamespace(), $wgPageTriageNamespaces ) ) {
			return true;
		}

		self::addToPageTriageQueue( $article->getId(), $article->getTitle(), $user );

		return true;
	}

	/**
	 * Compile the metadata on successful save, this is only for page in PageTriage Queue already
	 *
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleSaveComplete
	 * @param $article WikiPage
	 * @param $user
	 * @param $text
	 * @param $summary
	 * @param $minoredit
	 * @param $watchthis
	 * @param $sectionanchor
	 * @param $flags
	 * @param $revision
	 * @param $status
	 * @param $baseRevId
	 * @return bool
	 */
	public static function onArticleSaveComplete(
		$article, $user, $text, $summary, $minoredit, $watchthis, $sectionanchor, $flags, $revision,
		$status, $baseRevId
	) {
		global $wgPageTriageNamespaces;

		self::flushUserStatusCache( $article->getTitle() );

		if ( !in_array( $article->getTitle()->getNamespace(), $wgPageTriageNamespaces ) ) {
			return true;
		}

		DeferredUpdates::addCallableUpdate( function() use ( $article ) {
			// false will enforce a validation against pagetriage_page table
			$acp = ArticleCompileProcessor::newFromPageId(
				array( $article->getId() ), false, DB_MASTER );

			if ( $acp ) {
				// Register the article object so we can get the content and other useful information
				// this is primarily for replication delay from slave
				$acp->registerArticle( $article );
				$acp->compileMetadata();
			}
		} );

		return true;
	}

	/**
	 * Remove the metadata we added when the article is deleted.
	 *
	 * 'ArticleDeleteComplete': after an article is deleted
	 * @param $article WikiPage the WikiPage that was deleted
	 * @param $user User the user that deleted the article
	 * @param $reason string the reason the article was deleted
	 * @param $id int id of the article that was deleted
	 */
	public static function onArticleDeleteComplete( $article, $user, $reason, $id ) {
		global $wgPageTriageNamespaces;

		self::flushUserStatusCache( $article->getTitle() );

		if ( !in_array( $article->getTitle()->getNamespace(), $wgPageTriageNamespaces ) ) {
			return true;
		}

		// delete everything
		$pageTriage = new PageTriage( $id );
		$pageTriage->deleteFromPageTriage();
		return true;
	}

	/**
	 * Add page to page triage queue, check for autopatrol right if reviewed is not set
	 *
	 * This method should only be called from this class and its closures
	 *
	 * @param $pageId int
	 * @param $title Title
	 * @param $user User|null
	 * @param $reviewed numeric string See PageTriage::getValidReviewedStatus()
	 * @return bool
	 */
	public static function addToPageTriageQueue( $pageId, $title, $user = null, $reviewed = null ) {
		global $wgUseRCPatrol, $wgUseNPPatrol;

		$pageTriage = new PageTriage( $pageId );

		// action taken by system
		if ( is_null( $user ) ) {
			if ( is_null( $reviewed ) ) {
				$reviewed = '0';
			}
			return $pageTriage->addToPageTriageQueue( $reviewed );
		// action taken by a user
		} else {
			// set reviewed if it's not set yet
			if ( is_null( $reviewed ) ) {
				// check if this user has autopatrol right
				if ( ( $wgUseRCPatrol || $wgUseNPPatrol ) &&
					!count( $title->getUserPermissionsErrors( 'autopatrol', $user ) ) ) {
					$reviewed = 3;
				// if the user has no autopatrol right and doesn't really take any action,
				// this would be set to unreviewed by system.
				} else {
					return $pageTriage->addToPageTriageQueue( '0' );
				}
			}
			return $pageTriage->addToPageTriageQueue( $reviewed, $user );
		}
	}

	/**
	 * Add last time user visited the triage page to preferences.
	 * @param $user User object
	 * @param &$preferences array Preferences object
	 * @return bool
	 */
	public static function onGetPreferences( $user, &$preferences ) {
		$preferences['pagetriage-lastuse'] = array(
			'type' => 'api',
		);

		return true;
	}

	/**
	 * Flush user page/user talk page exsitance status, this function should
	 * be called when a page gets created/deleted/moved/restored
	 * @param $title
	 */
	private static function flushUserStatusCache( $title ) {
		global $wgMemc;

		if ( in_array( $title->getNamespace(), array( NS_USER, NS_USER_TALK ) ) ) {
			$wgMemc->delete( PageTriageUtil::userStatusKey( $title->getText() ) );
		}
	}

	/**
	 * Determines whether to show no-index for the article specified, show no-index if
	 * 1. the page contains a template listed in $wgPageTriageNoIndexTemplates page
	 * 2. the page is in triage queue and has not been triaged
	 * @param $article Article
	 * @return bool
	 */
	private static function shouldShowNoIndex( $article ) {
		global $wgPageTriageNoIndexTemplates;

		if ( $wgPageTriageNoIndexTemplates && $article->mParserOutput instanceof ParserOutput ) {
			$noIndexTitle = Title::newFromText( $wgPageTriageNoIndexTemplates, NS_MEDIAWIKI );
			if ( $noIndexTitle ) {
				$noIndexArticle = WikiPage::newFromID( $noIndexTitle->getArticleID() );
				if ( $noIndexArticle ) {
					$noIndexTemplateText = $noIndexArticle->getText();
					if ( $noIndexTemplateText ) {
						// Collect all the noindex template names into an array
						$noIndexTemplates = explode( '|', $noIndexTemplateText );
						// Properly format the template names to match what getTemplates() returns
						$noIndexTemplates = array_map(
							array( 'PageTriageHooks', 'formatTemplateName' ),
							$noIndexTemplates
						);
						foreach ( $article->mParserOutput->getTemplates() as $templates ) {
							foreach ( $templates as $template => $pageId ) {
								if ( in_array( $template, $noIndexTemplates ) ) {
									return true;
								}
							}
						}
					}
				}
			}
		}

		if ( PageTriageUtil::doesPageNeedTriage( $article ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Formats a template name to match the format returned by getTemplates()
	 * @param $template string
	 * @return string
	 */
	private static function formatTemplateName( $template ) {
		$template = ucfirst( trim( $template ) );
		$template = str_replace( ' ', '_', $template );
		return $template;
	}

	/**
	 * Handler for hook ArticleViewFooter, this will determine whether to load
	 * curation toolbar or 'mark as reviewed'/'reviewed' text
	 *
	 * @param &$article Article object to show link for.
	 * @param $patrolFooterShown bool whether the patrol footer is shown
	 * @return bool
	 */
	public static function onArticleViewFooter( $article, $patrolFooterShown ) {
		global $wgUser, $wgPageTriageMarkPatrolledLinkExpiry, $wgOut,
			$wgPageTriageEnableCurationToolbar, $wgRequest, $wgPageTriageNamespaces;

		// Overwrite the noindex rule defined in Article::view(), this also affects main namespace
		//if ( self::shouldShowNoIndex( $article ) ) {
		//	$wgOut->setRobotPolicy( 'noindex,nofollow' );
		//}

		// Only logged in users can review
		if ( !$wgUser->isLoggedIn() ) {
			return true;
		}

		// Don't show anything for user with no patrol right
		if ( !$article->getTitle()->quickUserCan( 'patrol' ) ) {
			return true;
		}

		// Only show in defined namespaces
		if ( !in_array( $article->getTitle()->getNamespace(), $wgPageTriageNamespaces ) ) {
			return true;
		}

		// Don't do anything if it's coming from Special:NewPages
		if ( $wgRequest->getVal( 'patrolpage' ) ) {
			return true;
		}

		// If the user hasn't visited Special:NewPagesFeed lately, don't do anything
		$lastUseExpired = false;
		$lastUse = $wgUser->getOption( 'pagetriage-lastuse' );
		if ( $lastUse ) {
			$lastUse = wfTimestamp( TS_UNIX, $lastUse );
			$now = wfTimestamp( TS_UNIX, wfTimestampNow() );
			$periodSince = $now - $lastUse;
		}
		if ( !$lastUse || $periodSince > $wgPageTriageMarkPatrolledLinkExpiry ) {
			$lastUseExpired = true;
		}

		// See if the page is in the PageTriage page queue
		// If it isn't, $needsReview will be null
		// Also, users without the autopatrol right can't review their own pages
		$needsReview = PageTriageUtil::doesPageNeedTriage( $article );
		if ( !is_null( $needsReview )
			&& !( $wgUser->getId() == $article->getOldestRevision()->getUser()
				&& !$wgUser->isAllowed( 'autopatrol' )
			)
		) {
			if ( $wgPageTriageEnableCurationToolbar || $wgRequest->getVal( 'curationtoolbar' ) === 'true' ) {
				// Load the JavaScript for the curation toolbar
				$wgOut->addModules( 'ext.pageTriage.toolbarStartup' );
				// Set the config flags in JavaScript
				$globalVars = array(
					'wgPageTriagelastUseExpired' => $lastUseExpired,
					'wgPageTriagePagePrefixedText' => $article->getTitle()->getPrefixedText()
				);
				$wgOut->addJsConfigVars( $globalVars );
			} else {
				if ( $needsReview ) {
					// show 'Mark as reviewed' link
					$msg = wfMessage( 'pagetriage-markpatrolled' )->text();
					$msg = Html::element(
						'a',
						array( 'href' => '#', 'class' => 'mw-pagetriage-markpatrolled-link' ),
						$msg
					);
				} else {
					// show 'Reviewed' text
					$msg = wfMessage( 'pagetriage-reviewed' )->escaped();
				}
				$wgOut->addModules( array( 'ext.pageTriage.article' ) );
				$html = Html::rawElement( 'div', array( 'class' => 'mw-pagetriage-markpatrolled' ), $msg );
				$wgOut->addHTML( $html );
			}
		}

		return true;
	}

	/**
	 * Sync records from patrol queue to triage queue
	 *
	 * 'MarkPatrolledComplete': after an edit is marked patrolled
	 * $rcid: ID of the revision marked as patrolled
	 * $user: user (object) who marked the edit patrolled
	 * $wcOnlySysopsCanPatrol: config setting indicating whether the user
	 * must be a sysop to patrol the edit
	 * @param $rcid int
	 * @param $user User
	 * @param $wcOnlySysopsCanPatrol
	 * @return bool
	 */
	public static function onMarkPatrolledComplete( $rcid, &$user, $wcOnlySysopsCanPatrol ) {
		$rc = RecentChange::newFromId( $rcid );

		if ( $rc ) {
			global $wgPageTriageNamespaces;
			if ( !in_array( $rc->getTitle()->getNamespace(), $wgPageTriageNamespaces ) ) {
				return true;
			}

			$pt = new PageTriage( $rc->getAttribute( 'rc_cur_id' ) );
			if ( $pt->addToPageTriageQueue( '2', $user, true /* fromRc */ ) ) {
				// Compile metadata for new page triage record
				$acp = ArticleCompileProcessor::newFromPageId( array( $rc->getAttribute( 'rc_cur_id' ) ) );
				if ( $acp ) {
					// page just gets added to pagetriage queue and hence not safe to use slave db
					// for BasicData since it's accessing pagetriage_page table
					$config = array(
						'LinkCount' => DB_SLAVE,
						'CategoryCount' => DB_SLAVE,
						'Snippet' => DB_SLAVE,
						'UserData' => DB_SLAVE,
						'DeletionTag' => DB_SLAVE
					);
					$acp->configComponentDb( $config );
					$acp->compileMetadata();
				}
			}
			$article = Article::newFromID( $rc->getAttribute( 'rc_cur_id' ) );
			if ( $article ) {
				PageTriageUtil::createNotificationEvent( $article, $user, 'pagetriage-mark-as-reviewed' );
			}
		}

		return true;
	}

	/**
	 * Update Article metadata when a user gets blocked
	 *
	 * 'BlockIpComplete': after an IP address or user is blocked
	 * @param $block Block the Block object that was saved
	 * @param $performer User the user who did the block (not the one being blocked)
	 * @return bool
	 */
	public static function onBlockIpComplete( $block, $performer ) {
		PageTriageUtil::updateMetadataOnBlockChange( $block );
		return true;
	}

	/**
	 * Send php config vars to js via ResourceLoader
	 *
	 * @param &$vars: variables to be added to the output of the startup module
	 * @return bool
	 */
	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $wgPageTriageCurationModules, $wgPageTriageNamespaces,
			$wgTalkPageNoteTemplate;

		// check if WikiLove is enabled
		if ( !class_exists( 'ApiWikiLove' ) ) {
			if ( array_key_exists( 'wikiLove', $wgPageTriageCurationModules ) ) {
				unset( $wgPageTriageCurationModules['wikiLove'] );
			}
		}

		$vars['wgPageTriageCurationModules'] = $wgPageTriageCurationModules;
		$vars['wgPageTriageNamespaces'] = $wgPageTriageNamespaces;
		$vars['wgTalkPageNoteTemplate'] = $wgTalkPageNoteTemplate;
		return true;
	}

	/**
	 * Add PageTriage events to Echo
	 *
	 * @param $notifications array a list of enabled echo events
	 * @param $notificationCategories array details for echo events
	 * @param $icons array of icon details
	 * @return bool
	 */
	public static function onBeforeCreateEchoEvent(
		&$notifications, &$notificationCategories, &$icons
	) {
		global $wgPageTriageEnabledEchoEvents;

		if ( $wgPageTriageEnabledEchoEvents ) {
			$notificationCategories['page-review'] = array(
				'priority' => 8,
				'tooltip' => 'echo-pref-tooltip-page-review',
			);
		}

		if ( in_array( 'pagetriage-mark-as-reviewed', $wgPageTriageEnabledEchoEvents ) ) {
			$notifications['pagetriage-mark-as-reviewed'] = array(
				'presentation-model' => 'PageTriageMarkAsReviewedPresentationModel',
				'primary-link' => array(
					'message' => 'notification-link-text-view-page',
					'destination' => 'title'
				),
				'category' => 'page-review',
				'group' => 'neutral',
				'formatter-class' => 'PageTriageNotificationFormatter',
				'title-message' => 'pagetriage-notification-mark-as-reviewed2',
				'title-params' => array( 'agent', 'title' ),
				'email-subject-message' => 'pagetriage-notification-mark-as-reviewed-email-subject2',
				'email-subject-params' => array( 'agent', 'title' ),
				'email-body-batch-message' => 'pagetriage-notification-mark-as-reviewed-email-batch-body',
				'email-body-batch-params' => array( 'title', 'agent' ),
				'icon' => 'reviewed',
			);
		}
		if ( in_array( 'pagetriage-add-maintenance-tag', $wgPageTriageEnabledEchoEvents ) ) {
			$notifications['pagetriage-add-maintenance-tag'] = array(
				'presentation-model' => 'PageTriageAddMaintenanceTagPresentationModel',
				'primary-link' => array(
					'message' => 'notification-link-text-view-page',
					'destination' => 'title'
				),
				'category' => 'page-review',
				'group' => 'neutral',
				'formatter-class' => 'PageTriageNotificationFormatter',
				'title-message' => 'pagetriage-notification-add-maintenance-tag2',
				'title-params' => array( 'agent', 'title', 'tag' ),
				'email-subject-message' => 'pagetriage-notification-add-maintenance-tag-email-subject2',
				'email-subject-params' => array( 'agent', 'title' ),
				'email-body-batch-message' => 'pagetriage-notification-add-maintenance-tag-email-batch-body',
				'email-body-batch-params' => array( 'title', 'agent' ),
				'icon' => 'reviewed',
			);
		}
		if ( in_array( 'pagetriage-add-deletion-tag', $wgPageTriageEnabledEchoEvents ) ) {
			$notifications['pagetriage-add-deletion-tag'] = array(
				'presentation-model' => 'PageTriageAddDeletionTagPresentationModel',
				'primary-link' => array(
					'message' => 'notification-link-text-view-page',
					'destination' => 'title'
				),
				'category' => 'page-review',
				'group' => 'negative',
				'formatter-class' => 'PageTriageNotificationFormatter',
				'title-message' => 'pagetriage-notification-add-deletion-tag2',
				'title-params' => array( 'agent', 'title', 'tag' ),
				'email-subject-message' => 'pagetriage-notification-add-deletion-tag-email-subject2',
				'email-subject-params' => array( 'agent', 'title' ),
				'email-body-batch-message' => 'pagetriage-notification-add-deletion-tag-email-batch-body',
				'email-body-batch-params' => array( 'title', 'agent' ),
				'icon' => 'trash',
			);
		}

		return true;
	}

	/**
	 * Add users to be notified on an echo event
	 * @param $event EchoEvent
	 * @param $users array
	 * @return bool
	 */
	public static function onEchoGetDefaultNotifiedUsers( $event, &$users ) {
		switch ( $event->getType() ) {
			// notify the page creator/starter
			case 'pagetriage-mark-as-reviewed':
			case 'pagetriage-add-maintenance-tag':
			case 'pagetriage-add-deletion-tag':
				if ( !$event->getTitle() ) {
					break;
				}

				$pageId = $event->getTitle()->getArticleID();

				$articleMetadata = new ArticleMetadata( array( $pageId ), false, DB_SLAVE );
				$metaData = $articleMetadata->getMetadata();

				if ( !$metaData ) {
					break;
				}

				if ( $metaData[$pageId]['user_id'] ) {
					$users[$metaData[$pageId]['user_id']] = User::newFromId( $metaData[$pageId]['user_id'] );
				}
			break;
		}
		return true;
	}

	/**
	 * Handler for AddNewAccount hook
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/AddNewAccount
	 * @param $user User object that was created.
	 * @param $byEmail bool True when account was created "by email".
	 * @return bool
	 */
	public static function onAccountCreated( $user, $byEmail ) {
		// New users get echo preferences set that are not the default settings for existing users.
		// Specifically, new users are opted into email notifications for page reviews.
		$user->setOption( 'echo-subscriptions-email-page-review', true );
		$user->saveSettings();
		return true;
	}

	public static function onUserMergeAccountFields( array &$updateFields ) {
		$updateFields[] = array( 'pagetriage_log', 'ptrl_user_id' );
		$updateFields[] = array( 'pagetriage_page', 'ptrp_last_reviewed_by' );

		return true;
	}
}
