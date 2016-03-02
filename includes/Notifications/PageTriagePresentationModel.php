<?php

abstract class PageTriagePresentationModel extends EchoEventPresentationModel {
	/**
	 * {@inheritdoc}
	 */
	public function canRender() {
		return $this->event->getTitle() instanceof Title;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPrimaryLink() {
		return array(
			'url' => $this->event->getTitle()->getFullURL(),
			'label' => $this->msg( 'notification-link-text-view-page' )->text(),
		);
	}

	protected function getTags() {
		// BC: the extra params array used to be the tags directly, now the tags are under the key 'tags'
		return $this->event->getExtraParam( 'tags', $this->event->getExtra() );
	}

	/**
	 * Returns an array of [tag list, amount of tags], to be used as msg params.
	 *
	 * @return array [(string) tag list, (int) amount of tags]
	 */
	protected function getTagsForOutput() {
		$tags = $this->getTags();

		if ( !is_array( $tags ) ) {
			return array( '', 0 );
		}

		return array( $this->language->commaList( $tags ), count( $tags ) );
	}

	function getBodyMessage() {
		$note = $this->event->getExtraParam( 'note' );
		return $note ? $this->msg( 'notification-body-page-triage-note' )->params( $note ) : false;
	}
}