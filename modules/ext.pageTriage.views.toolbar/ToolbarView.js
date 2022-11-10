// view for the floating toolbar

var ToolbarView,
	config = require( './config.json' ),
	// create an event aggregator
	eventBus = _.extend( {}, Backbone.Events ),
	// the current article
	article = new mw.pageTriage.Article( {
		eventBus: eventBus,
		pageId: mw.config.get( 'wgArticleId' ),
		includeHistory: true
	} ),
	toolbar,
	// array of tool instances
	tools;

// Used later via articleInfo.js
require( './../external/jquery.badge.js' );

// Add content language messages
mw.pageTriage.contentLanguageMessages.set( require( './contentLanguageMessages.json' ) );

// overall toolbar view
// currently, this is the main application view.
ToolbarView = Backbone.View.extend( {
	template: mw.template.get( 'ext.pageTriage.views.toolbar', 'ToolbarView.underscore' ),

	initialize: function () {
		var modules = config.PageTriageCurationModules;
		// An array of tool instances to put on the bar, ordered top-to-bottom
		tools = [];

		var MinimizeView = require( './minimize.js' );
		tools.push( new MinimizeView( { eventBus: eventBus, model: article, toolbar: this } ) );

		// article information
		if ( this.isFlyoutEnabled( 'articleInfo' ) ) {
			var ArticleInfoView = require( './articleInfo.js' );
			tools.push( new ArticleInfoView( { eventBus: eventBus, model: article, moduleConfig: modules.articleInfo } ) );
		}

		// wikilove
		if ( this.isFlyoutEnabled( 'wikiLove' ) ) {
			var WikiLoveView = require( './wikilove.js' );
			tools.push( new WikiLoveView( { eventBus: eventBus, model: article, moduleConfig: modules.wikiLove } ) );
		}

		// mark as reviewed
		if ( this.isFlyoutEnabled( 'mark' ) ) {
			var MarkView = require( './mark.js' );
			tools.push( new MarkView( { eventBus: eventBus, model: article, moduleConfig: modules.mark } ) );
		}

		// add tags
		if ( this.isFlyoutEnabled( 'tags' ) && config.PageTriageEnableEnglishWikipediaFeatures ) {
			var TagsView = require( './tags.js' );
			tools.push( new TagsView( { eventBus: eventBus, model: article, moduleConfig: modules.tags } ) );
		}

		// delete
		if ( this.isFlyoutEnabled( 'delete' ) && config.PageTriageEnableEnglishWikipediaFeatures ) {
			var DeleteView = require( './delete.js' );
			tools.push( new DeleteView( { eventBus: eventBus, model: article, moduleConfig: modules.delete } ) );
		}

		// next article, should be always on
		var NextView = require( './next.js' );
		tools.push( new NextView( { eventBus: eventBus, model: article } ) );
	},

	/**
	 * Check if the flyout is enabled
	 *
	 * @param {string} flyout
	 * @return {boolean}
	 */
	isFlyoutEnabled: function ( flyout ) {
		var modules = config.PageTriageCurationModules;

		// this flyout is disabled for curation toolbar
		if ( typeof modules[ flyout ] === 'undefined' ) {
			return false;
		}

		// this flyout is disabled for current namespace
		return ( modules[ flyout ].namespace.indexOf( mw.config.get( 'wgNamespaceNumber' ) ) !== -1 );
	},

	render: function () {
		// build the bar and insert into the page.
		// insert the empty toolbar into the document.
		$( 'body' ).append( this.template() );

		_.each( tools, function ( tool ) {
			// append the individual tool template to the toolbar's big tool div part
			// this is the icon and hidden div. (the actual tool content)
			$( '#mwe-pt-toolbar-main' ).append( tool.place() );
		} );

		// make it draggable
		$( '#mwe-pt-toolbar' ).draggable( {
			containment: 'window', // keep the curation bar inside the window
			delay: 200, // these options prevent unwanted drags when attempting to click buttons
			distance: 10,
			cancel: '.mwe-pt-tool-content'
		} );

		var that = this;
		// make clicking on the minimized toolbar expand to normal size
		$( '#mwe-pt-toolbar-vertical' ).on( 'click', function () {
			that.maximize( true );
		} );

		// since transform only works in IE 9 and higher, use writing-mode
		// to rotate the minimized toolbar content in older versions
		if ( $.client.test( { msie: [ [ '<', 9 ] ] }, null, true ) ) {
			$( '#mwe-pt-toolbar-vertical' ).css( 'writing-mode', 'tb-rl' );
		}

		// make the close button do something
		$( '.mwe-pt-toolbar-close-button' ).on( 'click', function () {
			that.hide( true );
		} );

		// Auto-resize all textareas as they type.
		$( '#mwe-pt-toolbar' ).off( 'input.mwe-pt-tool-flyout' )
			.on( 'input.mwe-pt-tool-flyout', 'textarea', function () {
				var newHeight = this.scrollHeight + 2, // +2 to account for line-height.
					maxHeight = 152; // Arbitrary, roughly 12 lines of text.

				if ( newHeight > maxHeight ) {
					this.style.height = maxHeight + 'px';
					this.style.overflowY = 'scroll';
				} else {
					this.style.height = 'auto';
					this.style.height = newHeight + 'px';
					this.style.overflowY = 'hidden';
				}
			} );

		// Create left menu link to reopen the toolbar. Hide it initially to avoid a
		// flash of content, we can show it later.
		if ( $( '#t-opencurationtoolbar' ).length === 0 ) {
			this.insertLink();
		}

		// Show the toolbar based on saved prefs.
		switch ( mw.user.options.get( 'userjs-curationtoolbar' ) ) {
			case 'hidden':
				this.hide();
				break;
			case 'minimized':
				this.minimize();
				$( '#mw-content-text .patrollink' ).hide();
				break;
			case 'maximized':
			/* falls through */
			default:
				this.maximize();
				$( '#mw-content-text .patrollink' ).hide();
				break;
		}
	},

	hide: function ( savePref ) {
		// hide everything
		$( '#mwe-pt-toolbar' ).hide();
		// reset the curation toolbar to original state
		$( '#mwe-pt-toolbar-inactive' ).css( 'display', 'none' );
		$( '#mwe-pt-toolbar-active' ).css( 'display', 'block' );
		$( '#mwe-pt-toolbar' ).removeClass( 'mwe-pt-toolbar-small' ).addClass( 'mwe-pt-toolbar-big' );
		if ( typeof savePref !== 'undefined' && savePref === true ) {
			this.setToolbarPreference( 'hidden' );
		}
		// Show the Open Page Curation link in the left menu
		$( '#t-opencurationtoolbar' ).show();
		// Show hidden patrol link in case they want to use that instead
		$( '#mw-content-text .patrollink' ).show();
	},

	minimize: function ( savePref ) {
		var dir = $( 'body' ).css( 'direction' ),
			toolbarPosCss = dir === 'ltr' ?
				{
					left: 'auto',
					right: 0
				} :
				// For RTL, flip
				{
					left: 0,
					right: 'auto'
				};

		// hide the Open Page Curation link in the left menu
		$( '#t-opencurationtoolbar' ).hide();
		// close any open tools by triggering showTool with empty tool param
		eventBus.trigger( 'showTool', '' );
		// hide the regular toolbar content
		$( '#mwe-pt-toolbar-active' ).hide();
		// show the minimized toolbar content
		$( '#mwe-pt-toolbar-inactive' ).show();
		// switch to smaller size
		$( '#mwe-pt-toolbar' ).removeClass( 'mwe-pt-toolbar-big' ).addClass( 'mwe-pt-toolbar-small' )
			// dock to the side of the screen
			.css( toolbarPosCss );
		// set a pref for the user so the minimize state is remembered
		if ( typeof savePref !== 'undefined' && savePref === true ) {
			this.setToolbarPreference( 'minimized' );
		}
	},

	maximize: function ( savePref ) {
		var dir = $( 'body' ).css( 'direction' ),
			toolbarPosCss = dir === 'ltr' ?
				{
					left: 'auto',
					right: 0
				} :
				// For RTL, flip
				{
					left: 0,
					right: 'auto'
				};

		// hide the Open Page Curation link in the left menu
		$( '#t-opencurationtoolbar' ).hide();
		// show the entire toolbar, in case it is hidden
		$( '#mwe-pt-toolbar' ).show();
		// hide the "Mark this page as patrolled" link
		$( '#mw-content-text .patrollink' ).hide();
		// hide the minimized toolbar content
		$( '#mwe-pt-toolbar-inactive' ).hide();
		// show the regular toolbar content
		$( '#mwe-pt-toolbar-active' ).show();
		// switch to larger size
		$( '#mwe-pt-toolbar' ).removeClass( 'mwe-pt-toolbar-small' ).addClass( 'mwe-pt-toolbar-big' )
			// reset alignment to the side of the screen (since the toolbar is wider now)
			.css( toolbarPosCss );
		// set a pref for the user so the minimize state is remembered
		if ( typeof savePref !== 'undefined' && savePref === true ) {
			this.setToolbarPreference( 'maximized' );
		}
	},
	setToolbarPreference: function ( state ) {
		return new mw.Api().saveOption( 'userjs-curationtoolbar', state );
	},
	insertLink: function () {
		var that = this,
			$link = $( '<li>' ).attr( 'id', 't-opencurationtoolbar' ).hide().append(
				$( '<a>' ).attr( 'href', '#' )
			);

		$link.find( 'a' )
			.text( mw.msg( 'pagetriage-toolbar-linktext' ) )
			.on( 'click', function () {
				that.maximize( true );
				this.blur();
				return false;
			} );
		$( '#p-tb' ).find( 'ul' ).append( $link );
		return true;
	}
} );

$( function () {
	article.fetch(
		{
			success: function () {
				// create an instance of the toolbar view
				toolbar = new ToolbarView( { eventBus: eventBus } );
				toolbar.render();
				article.set( 'successfulModelLoading', 1 );
			}
		}
	);
} );
