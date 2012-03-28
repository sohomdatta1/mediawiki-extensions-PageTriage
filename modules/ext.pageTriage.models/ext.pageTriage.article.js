// Article represents the metadata for a single article.
// ArticleList is a collection of articles for use in the list view
//
$( function() {
	mw.pageTriage = {
		Article: Backbone.Model.extend( {
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
					article.set( 'user_title', new mw.Title( userName, mw.config.get('wgNamespaceIds')['user'] ) );
					article.set( 'user_talk_title', new mw.Title( userName, mw.config.get('wgNamespaceIds')['user_talk'] ) );
					article.set( 'user_contribs_title', new mw.Title( gM( 'pagetriage-special-contributions' ) + '/' + userName ) );
				}
				article.set( 'title_url', mw.util.wikiUrlencode( article.get( 'title' ) ) );
			}

		} ),
	};
	
	// can't include this in the declaration above because it references the
	// object created therein.
	mw.pageTriage.ArticleList = Backbone.Collection.extend( {
		model: mw.pageTriage.Article,
		url: mw.util.wikiScript( 'api' ) + '?action=pagetriagelist&format=json',

		parse: function( response ) {
			// extract the useful bits of json.
			return response.pagetriagelist.pages;
		}
	} );
	
} );
