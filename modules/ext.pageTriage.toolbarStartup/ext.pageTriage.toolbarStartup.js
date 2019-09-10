$( function () {
	var ns = mw.config.get( 'wgNamespaceNumber' );

	// Only show curation toolbar for enabled namespaces, minus the draftspace.
	if ( mw.config.get( 'pageTriageNamespaces' ).indexOf( ns ) === -1 ||
		ns === mw.config.get( 'wgPageTriageDraftNamespaceId' )
	) {
		return;
	}

	// Load the curation toolbar
	mw.loader.using( 'ext.pageTriage.views.toolbar' )
		.then( function () {
			// Fire the 'ready' hook
			mw.hook( 'ext.pageTriage.toolbar.ready' )
				.fire( mw.pageTriage.actionQueue );
		} );
} );
