describe( '[Language Processing] Text to Speech (Amazon Polly) Tests', () => {
	before( () => {
		cy.login();
		cy.visit(
			'/wp-admin/tools.php?page=classifai&tab=language_processing&feature=feature_text_to_speech_generation'
		);
		cy.get(
			'#classifai_feature_text_to_speech_generation_post_types_post'
		).check( 'post' );
		cy.get( '#provider' ).select( 'aws_polly' );
		cy.get( '#access_key_id' ).clear();
		cy.get( '#access_key_id' ).type( 'SAMPLE_ACCESS_KEY' );
		cy.get( '#secret_access_key' ).clear();
		cy.get( '#secret_access_key' ).type( 'SAMPLE_SECRET_ACCESS_KEY' );
		cy.get( '#aws_region' ).clear();
		cy.get( '#aws_region' ).type( 'SAMPLE_SECRET_ACCESS_KEY' );
		cy.get( '#status' ).check();
		cy.get( '#submit' ).click();

		cy.get( '#voice' ).select( 'Aditi' );
		cy.get( '#submit' ).click();
		cy.optInAllFeatures();
		cy.disableClassicEditor();
	} );

	beforeEach( () => {
		cy.login();
	} );

	it( 'Generates audio from text', () => {
		cy.createPost( {
			title: 'Text to Speech test',
			content:
				"This feature uses Amazon Polly's Text to Speech capabilities.",
		} );

		cy.get( 'button[aria-label="Close panel"]' ).click();
		cy.openDocumentSettingsSidebar();
		cy.get( '.classifai-panel' ).click();
		cy.get( '#classifai-audio-controls__preview-btn' ).should( 'exist' );
	} );

	it( 'Audio controls are visible if supported by post type', () => {
		cy.visit( '/text-to-speech-test/' );
		cy.get( '.class-post-audio-controls' ).should( 'be.visible' );
	} );

	it( 'a11y - aria-labels', () => {
		cy.visit( '/text-to-speech-test/' );
		cy.get( '.dashicons-controls-play' ).should( 'be.visible' );
		cy.get( '.class-post-audio-controls' ).should(
			'have.attr',
			'aria-label',
			'Play audio'
		);

		cy.get( '.class-post-audio-controls' ).click();

		cy.get( '.dashicons-controls-play' ).should( 'not.be.visible' );
		cy.get( '.class-post-audio-controls' ).should(
			'have.attr',
			'aria-label',
			'Pause audio'
		);

		cy.get( '.class-post-audio-controls' ).click();
		cy.get( '.dashicons-controls-play' ).should( 'be.visible' );
		cy.get( '.class-post-audio-controls' ).should(
			'have.attr',
			'aria-label',
			'Play audio'
		);
	} );

	it( 'a11y - keyboard accessibility', () => {
		cy.visit( '/text-to-speech-test/' );
		cy.get( '.class-post-audio-controls' )
			.tab( { shift: true } )
			.tab()
			.type( '{enter}' );
		cy.get( '.dashicons-controls-pause' ).should( 'be.visible' );
		cy.get( '.class-post-audio-controls' ).should(
			'have.attr',
			'aria-label',
			'Pause audio'
		);

		cy.get( '.class-post-audio-controls' ).type( '{enter}' );
		cy.get( '.dashicons-controls-play' ).should( 'be.visible' );
		cy.get( '.class-post-audio-controls' ).should(
			'have.attr',
			'aria-label',
			'Play audio'
		);
	} );

	it( 'Can see the enable button in a post (Classic Editor)', () => {
		cy.enableClassicEditor();

		cy.classicCreatePost( {
			title: 'Text to Speech test classic',
			content:
				"This feature uses Amazon Polly's Text to Speech capabilities.",
			postType: 'post',
		} );

		cy.get( '#classifai-text-to-speech-meta-box' ).should( 'exist' );
		cy.get( '#classifai_synthesize_speech' ).check();
		cy.get( '#classifai-audio-preview' ).should( 'exist' );

		cy.visit( '/text-to-speech-test/' );
		cy.get( '.class-post-audio-controls' ).should( 'be.visible' );

		cy.disableClassicEditor();
	} );
} );
