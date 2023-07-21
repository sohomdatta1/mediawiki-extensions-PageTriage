const { Article } = require( 'ext.pageTriage.util' );
let pageTriageDeletionTagsOptions, DeleteToolView, model, modelRedirect, eventBus;

describe( 'DeleteToolView', () => {
	beforeEach( () => {
		eventBus = _.extend( {}, Backbone.Events );
		model = new Article( {
			eventBus,
			pageId: 5,
			includeHistory: true
		} );

		modelRedirect = new Article( {
			// eslint-disable-next-line camelcase
			is_redirect: 1,
			eventBus,
			pageId: 5,
			includeHistory: true
		} );

		mw.config.get = jest.fn( ( key ) => {
			switch ( key ) {
				case 'wgPageName':
					return 'PageName';
				default:
					return null;
			}
		} );
		// needs to be loaded after mw.config.get has been defined to avoid fatal.
		const defaultTagsOptions = require( 'ext.pageTriage.defaultTagsOptions' );
		// for first test cache current value
		if ( !pageTriageDeletionTagsOptions ) {
			pageTriageDeletionTagsOptions = $.extend( true, {}, defaultTagsOptions.pageTriageDeletionTagsOptions );
		} else {
			// reset. There might have been side effects
			defaultTagsOptions.pageTriageDeletionTagsOptions = pageTriageDeletionTagsOptions;
		}
		$.pageTriageDeletionTagsOptions = $.extend( true, {}, pageTriageDeletionTagsOptions );

		DeleteToolView = require( '../../../modules/ext.pageTriage.views.toolbar/delete.js' );
	} );

	const checkSetup = () => {
		// Check that there were side-effects on the global 😱
		expect(
			$.pageTriageDeletionTagsOptions.Main.xfd.tags.redirectsfordiscussion.label
		).toBe(
			'pagetriage-del-tags-redirectsfordiscussion-label'
		);
	};

	describe( 'setupDeletionTags', () => {
		test( 'default', () => {
			checkSetup();
			const toolbar = new DeleteToolView( { eventBus, model } );
			expect( toolbar.deletionTagsOptions.xfd ).toBe( undefined );
			toolbar.setupDeletionTags();
			expect( toolbar.deletionTagsOptions.xfd.label ).toBe(
				'pagetriage-del-tags-articlefordeletion-label'
			);
			// Check that there were side-effects  😱
			expect( toolbar.deletionTagsOptions.xfd.tags.redirectsfordiscussion ).toBe(
				undefined
			);
			// Check that there were side-effects on the global 😱
			expect(
				$.pageTriageDeletionTagsOptions.Main.xfd.tags.redirectsfordiscussion
			).toBe(
				undefined
			);
		} );

		test( 'redirect', () => {
			checkSetup();
			const toolbar = new DeleteToolView( { eventBus, model: modelRedirect } );
			expect( toolbar.deletionTagsOptions.xfd ).toBe( undefined );
			toolbar.setupDeletionTags();
			expect( toolbar.deletionTagsOptions.xfd.label ).toBe(
				'pagetriage-del-tags-redirectsfordiscussion-label'
			);
			expect( toolbar.deletionTagsOptions.xfd.tags.articlefordeletion ).toBe(
				undefined
			);
			// Check that there were side-effects on the global 😱
			expect(
				$.pageTriageDeletionTagsOptions.Main.xfd.tags.articlefordeletion
			).toBe(
				undefined
			);
		} );
	} );

	test( 'notifyUser', () => {
		const toolbar = new DeleteToolView( { eventBus, model } );
		toolbar.selectedTag.tagKey = {
			usesSubpages: false
		};

		const msg = toolbar.notifyUser( {
			tagCount: 1,
			tagKey: 'tagKey'
		} );

		return msg.then( function () {
			expect( true ).toBe( true );
		} );
	} );
} );
