$( function() {
	// view for the floating toolbar

	// create an event aggregator
	var eventBus = _.extend( {}, Backbone.Events );

	// instantiate the collection of articles
	var articles = new mw.pageTriage.ArticleList( { eventBus: eventBus } );
	var tools;
	
	// overall toolbar view
	// currently, this is the main application view.
	mw.pageTriage.ToolbarView = Backbone.View.extend( {
		template: mw.pageTriage.viewUtil.template( { 'view': 'toolbar', 'template': 'toolbarView.html' } ),
		
		initialize: function() {
			// TODO: decide here which tools to put on the bar, based on namespace, status, etc.
			// create instances of each of those tools, and build an ordered tools array.
			tools = new Array;

			// add an articleInfo for testing.
			tools.push( new mw.pageTriage.ArticleInfoView( { eventBus: eventBus } ) );
			// add tags
			tools.push( new mw.pageTriage.TagsView( { eventBus: eventBus } ) );
			// and a generic abstract toolView (which does nothing, but is fine for testing)
			tools.push( new mw.pageTriage.ToolView( { eventBus: eventBus } ) );
			
			// if we someday want this configurable on-wiki, this could load some js from
			// the MediaWiki namespace that generates the tools array instead.			
		},
		
		render: function() {			
			// build the bar and insert into the page.

			// insert the empty toolbar into the document.
			$('body').append( this.template() );

			_.each( tools, function( tool ) {
				// append the individual tool template to the toolbar's big tool div part
				// this is the icon and hidden div. (the actual tool content)
				$( '#mwe-pt-toolbar-main' ).append( tool.place() );
			} );
		}
	} );

	// create an instance of the toolbar view
	var toolbar = new mw.pageTriage.ToolbarView( { eventBus: eventBus } );
	toolbar.render();
} );
