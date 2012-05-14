// Article represents the metadata for a single article.
// ArticleList is a collection of articles for use in the list view
//
$( function() {
	if ( !mw.pageTriage ) {
		mw.pageTriage = {};
	}
	mw.pageTriage.Article = Backbone.Model.extend( {
			defaults: {
			title: 'Empty Article',
			pageid: ''
		},

		initialize: function() {
			this.bind( 'change', this.formatMetadata, this );
		},

		formatMetadata: function ( article ) {
			var creation_date_parsed = Date.parseExact( article.get( 'creation_date' ), 'yyyyMMddHHmmss' );
			article.set('creation_date_pretty', creation_date_parsed.toString( gM( 'pagetriage-creation-dateformat' ) ) );
			
			// sometimes user info isn't set, so check that first.
			if( article.get( 'user_creation_date' ) ) {
				var user_creation_date_parsed = Date.parseExact( article.get( 'user_creation_date' ), 'yyyyMMddHHmmss' );
				article.set( 'user_creation_date_pretty', user_creation_date_parsed.toString( gM( 'pagetriage-user-creation-dateformat' ) ) );
			} else {
				article.set( 'user_creation_date_pretty', '');
			}

			var userName = article.get( 'user_name' );
			if( userName ) {
				var userTitle     = new mw.Title( userName, mw.config.get('wgNamespaceIds')['user'] );
				var userTalkTitle = new mw.Title( userName, mw.config.get('wgNamespaceIds')['user_talk'] );

				article.set( 'user_title_url', this.constructLink( userTitle ) );
				article.set( 'user_talk_title_url', this.constructLink( userTalkTitle ) );
				article.set( 'user_contribs_title', new mw.Title( gM( 'pagetriage-special-contributions' ) + '/' + userName ) );
				article.set( 'userPageLinkClass', userTitle.exists() ? '' : 'class="new"' );
				article.set( 'talkPageLinkClass', userTalkTitle.exists() ? '' : 'class="new"' );
			}
			article.set( 'title_url', mw.util.wikiUrlencode( article.get( 'title' ) ) );
		},

		constructLink: function ( title ) {
			var url = title.getUrl();
			if ( !title.exists() ) {
				var mark = ( url.indexOf( '?' ) === -1 ) ? '?' : '&';
				url = url + mark + 'action=edit&redlink=1';
			}
			return url;
		}

	} );

	// can't include this in the declaration above because it references the
	// object created therein.
	mw.pageTriage.ArticleList = Backbone.Collection.extend( {
		moreToLoad: true,
		model: mw.pageTriage.Article,

		apiParams: {
			limit: 20,
			dir: 'newestfirst',
			namespace: 0,
			showreviewed: 1,
			showdeleted: 1,
			showredirs: 0
			/*
			showbots: 0,
			no_category: 0,
			no_inbound_links: 0,
			non_autoconfirmed_users: 0,
			blocked_users: 0,
			username: null
			*/
		},

		initialize: function( options ) {
			this.eventBus = options.eventBus;
			this.eventBus.bind( "filterSet", this.setParams );
			
			// pull any saved filter settings from the user's cookies
			var savedFilterSettings = $.cookie( 'NewPageFeedFilterOptions' );
			if ( savedFilterSettings ) {
				this.setParams( $.parseJSON( savedFilterSettings ) );
			}
		},

		url: function() {
			var url = mw.util.wikiScript( 'api' ) + '?action=pagetriagelist&format=json&' + $.param( this.apiParams );
			return url;
		},

		parse: function( response ) {
			// See if the fetch returned an extra page or not. This lets us know if there are more 
			// pages to load in a subsequent fetch.
			if ( response.pagetriagelist.pages && response.pagetriagelist.pages.length > this.apiParams.limit ) {
				// Remove the extra page from the list
				response.pagetriagelist.pages.pop();
				this.moreToLoad = true;
			} else {
				// We have no more pages to load.
				this.moreToLoad = false;
			}

			// Check if user pages exist or should be redlinks
			for ( var title in response.pagetriagelist.userpagestatus ) {
				mw.Title.exist.set( title );
			}
			// extract the useful bits of json.
			return response.pagetriagelist.pages;
		},

		setParams: function( apiParams ) {
			this.apiParams = apiParams;
		},

		setParam: function( paramName, paramValue ) {
			this.apiParams[paramName] = paramValue;
		},
		
		encodeFilterParams: function() {
			var encodedString = '';
			var paramArray = new Array;
			var _this = this;
			$.each( this.apiParams, function( key, val ) {                    
				var str = '"' + key + '":';
				if ( typeof val === 'string' ) {
					val = '"' + val.replace(/[\"]/g, '\\"') + '"';
				}
				str += val;
				paramArray.push( str );
			} );
			encodedString = '{ ' + paramArray.join( ', ' ) + ' }';
			return encodedString;
		},
		
		// Save the filter parameters to a cookie
		saveFilterParams: function() {
			var cookieString = this.encodeFilterParams();
			$.cookie( 'NewPageFeedFilterOptions', cookieString, { expires: 1 } );
		},
		
		getParam: function( key ) {
			return this.apiParams[key];
		}

	} );

} );
