<?php

namespace MediaWiki\Extension\PageTriage\ArticleCompile;

/**
 * Tags for AfC submission state.
 */
class ArticleCompileAfcTag extends ArticleCompileInterface {

	const UNSUBMITTED = 1;
	const PENDING = 2;
	const UNDER_REVIEW = 3;
	const DECLINED = 4;

	public function __construct( $pageId, $componentDb = DB_MASTER, $articles = null ) {
		parent::__construct( $pageId, $componentDb, $articles );
	}

	/**
	 * AFC categories in priority order (i.e. the first found will be used if a page is in more
	 * than one of these categories). UNSUBMITTED is not actually a category, rather the absence
	 * of the other categories.
	 * @return string[]
	 */
	public static function getAfcCategories() {
		return [
			self::PENDING => 'Pending_AfC_submissions',
			self::UNDER_REVIEW => 'Pending_AfC_submissions_being_reviewed_now',
			self::DECLINED => 'Declined_AfC_submissions',
		];
	}

	/**
	 * Implements ArticleCompileInterface::compile(), called when generating tags.
	 * @return bool
	 */
	public function compile() {
		foreach ( $this->mPageId as $pageId ) {
			// Default to unsubmitted state; will be overriden if relevant category is present.
			$this->metadata[$pageId]['afc_state'] = self::UNSUBMITTED;

			$parserOutput = $this->getParserOutputByPageId( $pageId );
			if ( !$parserOutput ) {
				continue;
			}
			$categories = array_keys( $parserOutput->getCategories() );
			foreach ( $categories as $category ) {
				$afcStateValue = array_search( $category, $this->getAfcCategories() );
				if ( $afcStateValue ) {
					$this->metadata[$pageId]['afc_state'] = $afcStateValue;
					// Only set the first found category (highest priority one).
					break;
				}
			}
		}
		return true;
	}

}