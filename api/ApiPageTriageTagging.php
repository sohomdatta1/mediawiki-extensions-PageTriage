<?php

class ApiPageTriageTagging extends ApiBase {

	public function execute() {
		global $wgPageTriageProjectLink, $wgContLang;

		$params = $this->extractRequestParams();

		if ( !ArticleMetadata::validatePageId( [ $params['pageid'] ], DB_SLAVE ) ) {
			$this->dieUsage(
				'The page specified does not exist in pagetriage queue',
				'bad-pagetriage-page'
			);
		}

		$article = Article::newFromID( $params['pageid'] );

		if ( !$article ) {
			$this->dieUsage( "The page specified does not exist", 'bad-page' );
		}

		$title = $article->getTitle();

		if ( !$title->userCan( 'create' ) || !$title->userCan( 'edit' )
			|| !$title->userCan( 'patrol' ) ) {
			$this->dieUsage( "You don't have permission to do that", 'permission-denied' );
		}

		if ( $this->getUser()->pingLimiter( 'pagetriage-tagging-action' ) ) {
			$this->dieUsageMsg( [ 'actionthrottledtext' ] );
		}

		$apiParams = [];
		if ( $params['top'] ) {
			$apiParams['prependtext'] = $params['top'] . "\n\n";
		}
		if ( $params['bottom'] ) {
			$apiParams['appendtext'] = "\n\n" . $params['bottom'];
		}

		// Parse tags into a human readable list for the edit summary
		$tags = $wgContLang->commaList( $params['taglist'] );

		// Check if the page has been nominated for deletion
		if ( $params['deletion'] ) {
			$articleMetadata = new ArticleMetadata( [ $params['pageid'] ] );
			$metaData = $articleMetadata->getMetadata();
			if ( isset( $metaData[$params['pageid']] ) ) {
				foreach ( [ 'csd_status', 'prod_status', 'blp_prod_status', 'afd_status' ] as $val ) {
					if ( $metaData[$params['pageid']][$val] == '1' ) {
						$this->dieUsage(
							'The page has been nominated for deletion',
							'pagetriage-tag-deletion-error'
						);
					}
				}
			} else {
				$this->dieUsage(
					'The page specified does not exist in pagetriage queue',
					'bad-pagetriage-page'
				);
			}
		}

		if ( $apiParams ) {
			$projectLink = '[['
				. $wgPageTriageProjectLink . '|'
				. wfMessage( 'pagetriage-pagecuration' )->inContentLanguage()->plain()
				. ']]';
			if ( $params['deletion'] ) {
				$editSummary = wfMessage(
					'pagetriage-del-edit-summary',
					$projectLink,
					$tags
				)->inContentLanguage()->plain();
			} else {
				$editSummary = wfMessage(
					'pagetriage-tags-edit-summary',
					$projectLink,
					$tags
				)->inContentLanguage()->plain();
			}

			// tagging something for deletion should automatically watchlist it
			if ( $params['deletion'] ) {
				$apiParams['watchlist'] = 'watch';
			}

			// Perform the text insertion
			$api = new ApiMain(
					new DerivativeRequest(
						$this->getRequest(),
						$apiParams + [
							'action'	=> 'edit',
							'title'		=> $title->getFullText(),
							'token'		=> $params['token'],
							'summary'	=> $editSummary,
						],
						true
					),
					true
				);

			$api->execute();

			$note = $wgContLang->truncate( $params['note'], 150 );

			// logging to the logging table
			if ( $params['taglist'] ) {
				if ( $params['deletion'] ) {
					$entry = [
						// We want delete tag to have its own log as well as be included under page curation log
						// Todo: Find a way to filter log by action (subtype) so the deletion log can be removed
						'pagetriage-curation' => 'delete',
						'pagetriage-deletion' => 'delete'
					];
					PageTriageUtil::createNotificationEvent(
						$article,
						$this->getUser(),
						'pagetriage-add-deletion-tag',
						[
							'tags' => $params['taglist'],
							'note' => $note,
						]
					);
				} else {
					$entry = [
						'pagetriage-curation' => 'tag'
					];
					PageTriageUtil::createNotificationEvent(
						$article,
						$this->getUser(),
						'pagetriage-add-maintenance-tag',
						[
							'tags' => $params['taglist'],
							'note' => $note,
							'revId' => $api->getResult()->getResultData( [ 'edit', 'newrevid' ] ),
						]
					);
				}

				foreach ( $entry as $type => $action ) {
					$logEntry = new ManualLogEntry( $type, $action );
					$logEntry->setPerformer( $this->getUser() );
					$logEntry->setTarget( $article->getTitle() );
					if ( $note ) {
						$logEntry->setComment( $note );
					}
					$logEntry->setParameters( [
						'tags' => $params['taglist']
					] );
					$logEntry->publish( $logEntry->insert() );
				}
			}
		}

		$result = [ 'result' => 'success' ];
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getTokenSalt() {
		return '';
	}

	public function getAllowedParams() {
		return [
			'pageid' => [
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_TYPE => 'integer'
			],
			'token' => [
				ApiBase::PARAM_REQUIRED => true
			],
			'top' => null,
			'bottom' => null,
			'deletion' => [
				ApiBase::PARAM_REQUIRED => false,
				ApiBase::PARAM_TYPE => 'boolean'
			],
			'note' => null,
			'taglist' => [
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_ISMULTI => true
			],
		];
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getParamDescription() {
		return [
			'pageid' => 'The article for which to be tagged',
			'token' => 'Edit token',
			'top' => 'The tagging text to be added to the top of an article',
			'bottom' => 'The tagging text to be added to the bottom of an article',
			'deletion' => 'Whether or not the tagging is for a deletion nomination',
			'note' => 'Personal note to page creators from reviewers',
			'taglist' => 'Pipe-separated list of tags',
		];
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getDescription() {
		return 'Add tags to an article';
	}
}
