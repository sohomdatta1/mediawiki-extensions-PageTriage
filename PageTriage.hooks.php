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
		global $wgUser;

		$pageId = $newTitle->getArticleID();

		// Delete cache for record if it's in pagetriage queue
		$articleMetadata = new ArticleMetadata( array( $pageId ) );
		$articleMetadata->flushMetadataFromCache();

		$oldNamespace = $oldTitle->getNamespace();
		$newNamespace = $newTitle->getNamespace();
		// Do nothing further on if
		// 1. the page move is within the same namespace or
		// 2. the new page is not in article (main) namespace
		if ( $oldNamespace === $newNamespace || $newNamespace !== NS_MAIN ) {
			return true;
		}

		// New record to pagetriage queue, compile metadata
		if ( self::addToPageTriageQueue( $pageId, $newTitle, $wgUser, false ) ) {
			$acp = ArticleCompileProcessor::newFromPageId( array( $pageId ) );
			if ( $acp ) {
				$acp->compileMetadata();
			}
		}

		return true;
	}

	/**
	 * Add a page back to queue after the page gets restored
	 * @param $title Title corresponding to the article restored
	 * @param $create: Whether or not the restoration caused the page to be created
	 *			(i.e. it didn't exist before)
	 * @param $comment: The comment associated with the undeletion.
	 */
	public static function onArticleUndelete( $title, $create, $comment ) {
		global $wgUser;

		$pageId = $title->getArticleID();
		if ( $pageId && self::addToPageTriageQueue( $pageId, $title, $wgUser, false ) ) {
			$acp = ArticleCompileProcessor::newFromPageId( array( $pageId ) );
			if ( $acp ) {
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
	 * @param $article WikiPage the WikiPage edited
	 * @param $rev Revision the new revision
	 * @param $baseID int the revision ID this was based on, if any
	 * @param $user User the editing user
	 * @return bool
	 */
	public static function onNewRevisionFromEditComplete( $article, $rev, $baseID, $user ) {
		$prev = $rev->getPrevious();
		if ( $prev && !$article->isRedirect() && $article->isRedirect( $prev->getRawText() ) ) {
			self::addToPageTriageQueue( $article->getId(), $article->getTitle(), $user );
		}
		return true;
	}

	/**
	 * Insert new page into PageTriage Queue
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
	public static function onArticleInsertComplete( $article, $user, $text, $summary, $isMinor, $isWatch, $section, $flags, $revision ) {
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
	public static function onArticleSaveComplete( $article, $user, $text, $summary, $minoredit, $watchthis, $sectionanchor, $flags, $revision, $status, $baseRevId ) {
		$acp = ArticleCompileProcessor::newFromPageId( array( $article->getId() ) );
		if ( $acp ) {
			// Register the article object so we can get the content and other useful information
			// this is primarily for replication delay from slave
			$acp->registerArticle( $article );
			$acp->compileMetadata();
		}

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
		// delete everything
		$pageTriage = new PageTriage( $id );
		$pageTriage->deleteFromPageTriage();
		return true;
	}

	/**
	 * Add page to page triage queue
	 * @param $pageId int
	 * @param $title Title
	 * @param $user User|null
	 * @param $patrolled bool - should the page be added to the queue as reviewed or not
	 * @return bool
	 */
	private static function addToPageTriageQueue( $pageId, $title, $user = null, $patrolled = null ) {
		global $wgUser, $wgUseRCPatrol, $wgUseNPPatrol;

		$user = is_null( $user ) ? $wgUser : $user;

		if ( is_null( $patrolled ) ) {
			$patrolled = ( $wgUseRCPatrol || $wgUseNPPatrol ) && !count(
					$title->getUserPermissionsErrors( 'autopatrol', $user ) );
		}

		$pageTriage = new PageTriage( $pageId );
		// We don't pass $user for logging if the system sets the review/patrol status to '0'
		if ( $patrolled ) {
			return $pageTriage->addToPageTriageQueue( '1', $user );
		} else {
			return $pageTriage->addToPageTriageQueue( '0' );
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
			'type' => 'hidden',
		);

		return true;
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

		if ( $wgPageTriageNoIndexTemplates && $article->mParserOutput instanceof ParserOutput) {
			$noIndexTitle = Title::newFromText( $wgPageTriageNoIndexTemplates, NS_MEDIAWIKI );
			if ( $noIndexTitle ) {
				$noIndexArticle = WikiPage::newFromID( $noIndexTitle->getArticleID() );
				if ( $noIndexArticle ) {
					$noIndexTemplateText = $noIndexArticle->getText();
					if ( $noIndexTemplateText ) {
						// Collect all the noindex template names into an array
						$noIndexTemplates = explode( '|', $noIndexTemplateText );
						// Properly format the template names to match what getTemplates() returns
						$noIndexTemplates = array_map( array( 'PageTriageHooks', 'formatTemplateName' ), $noIndexTemplates );
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
	 * Adds "mark as patrolled" link to articles
	 *
	 * @param &$article Article object to show link for.
	 * @return bool
	 */
	public static function onArticleViewFooter( $article ) {
		global $wgUser, $wgPageTriageMarkPatrolledLinkExpiry, $wgOut, 
			$wgPageTriageEnableCurationToolbar, $wgRequest;

		// Overwrite the noindex rule defined in Article::view(), this also affects main namespace
		if ( self::shouldShowNoIndex( $article ) ) {
			$wgOut->setRobotPolicy( 'noindex,nofollow' );
		}

		// the presence of rcid means this is coming from Special:NewPages,
		// and hence don't make any interference, this also applies to
		// user with no right
		if ( $wgRequest->getVal( 'rcid' ) ) {
			return true;
		}

		// don't show anything for user with no patrol right
		if ( !$article->getTitle()->quickUserCan('patrol') ) {
			return true;
		}

		// Only show in article and user namespaces
		$namespace = $article->getTitle()->getNamespace();
		if ( $namespace !== NS_MAIN && $namespace !== NS_USER ) {
			return true;
		}

		// If the user hasn't visited Special:NewPagesFeed lately, don't do anything
		$lastUse = $wgUser->getOption( 'pagetriage-lastuse' );
		if ( $lastUse ) {
			$lastUse = wfTimestamp( TS_UNIX, $lastUse );
			$now = wfTimestamp( TS_UNIX, wfTimestampNow() );
			$periodSince = $now - $lastUse;
		}
		if ( !$lastUse || $periodSince > $wgPageTriageMarkPatrolledLinkExpiry ) {
			return true;
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
			if ( $wgPageTriageEnableCurationToolbar ) {
				// Load the JavaScript for the curation toolbar
				$wgOut->addModules( 'ext.pageTriage.toolbarStartup' );
			} else {
				if ( $needsReview ) {
					// show 'Mark as reviewed' link
					$msg = wfMessage( 'pagetriage-markpatrolled' )->text();
					$msg = Html::element( 'a', array( 'href' => '#', 'class' => 'mw-pagetriage-markpatrolled-link' ), $msg );
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
			$pt = new PageTriage( $rc->getAttribute( 'rc_cur_id' ) );
			if ( $pt->addToPageTriageQueue( '1', $user, $fromRc = true ) ) {
				// Compile metadata for new page triage record
				$acp = ArticleCompileProcessor::newFromPageId( array( $rc->getAttribute( 'rc_cur_id' ) ) );
				if ( $acp ) {
					$acp->compileMetadata();
				}
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
	 * Send php config vars to js
	 *
	 * makeGlobalVariablesScript: right before OutputPage->getJSVars returns the vars
	 * @param &$vars: variable (or multiple variables) to be added into the output of Skin::makeVariablesScript
	 * @return bool
	 */	
	public static function onMakeGlobalVariablesScript( &$vars ) {
		global $wgPageTriageToolbarInfoHelpLink;
		$vars['wgPageTriageToolbarInfoHelpLink'] = $wgPageTriageToolbarInfoHelpLink;
		return true;
	}
}
