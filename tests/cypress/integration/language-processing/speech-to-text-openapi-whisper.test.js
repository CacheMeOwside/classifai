import { getWhisperData } from '../../plugins/functions';

describe( '[Language processing] Speech to Text Tests', () => {
	it( 'Can save OpenAI Whisper "Language Processing" settings', () => {
		cy.visit(
			'/wp-admin/tools.php?page=classifai&tab=language_processing&provider=openai_whisper'
		);

		cy.get( '#api_key' ).clear().type( 'password' );

		cy.get( '#enable_transcripts' ).check();
		cy.get( '#openai_whisper_speech_to_text_roles_administrator' ).check();
		cy.get( '#submit' ).click();
	} );

	let audioEditLink = '';
	let mediaModalLink = '';
	it( 'Can see OpenAI Whisper language processing actions on edit media page and verify generated data.', () => {
		cy.visit( '/wp-admin/media-new.php' );
		cy.get( '#plupload-upload-ui' ).should( 'exist' );
		cy.get( '#plupload-upload-ui input[type=file]' ).attachFile(
			'audio.mp3'
		);

		cy.get( '#media-items .media-item a.edit-attachment' ).should(
			'exist'
		);
		cy.get( '#media-items .media-item a.edit-attachment' )
			.invoke( 'attr', 'href' )
			.then( ( editLink ) => {
				audioEditLink = editLink;
				cy.visit( editLink );
			} );

		// Verify metabox has processing actions.
		cy.get( '.postbox-header h2, #attachment_meta_box h2' )
			.first()
			.contains( 'ClassifAI Audio Processing' );
		cy.get( '.misc-publishing-actions label[for=retranscribe]' ).contains(
			'Re-transcribe'
		);

		// Verify generated data.
		cy.get( '#attachment_content' ).should(
			'have.value',
			getWhisperData()
		);
	} );

	it( 'Can see OpenAI Whisper language processing actions on media model', () => {
		const audioId = audioEditLink.split( 'post=' )[ 1 ]?.split( '&' )[ 0 ];
		mediaModalLink = `wp-admin/upload.php?item=${ audioId }`;
		cy.visit( mediaModalLink );
		cy.get( '.media-modal' ).should( 'exist' );

		// Verify language processing actions.
		cy.get( '#classifai-retranscribe' ).contains( 'Re-transcribe' );
	} );

	it( 'Can disable OpenAI Whisper language processing features', () => {
		cy.visit(
			'/wp-admin/tools.php?page=classifai&tab=language_processing&provider=openai_whisper'
		);

		// Disable features
		cy.get( '#enable_transcripts' ).uncheck();
		cy.get( '#submit' ).click();

		// Verify features are not present in attachment metabox.
		cy.visit( audioEditLink );
		cy.get( '.misc-publishing-actions label[for=retranscribe]' ).should(
			'not.exist'
		);

		// Verify features are not present in media modal.
		cy.visit( mediaModalLink );
		cy.get( '.media-modal' ).should( 'exist' );
		cy.get( '#classifai-retranscribe' ).should( 'not.exist' );
	} );

	it( 'Can enable/disable speech to text feature by role', () => {
		// Enable feature.
		cy.visit(
			'/wp-admin/tools.php?page=classifai&tab=language_processing&provider=openai_whisper'
		);
		cy.get( '#enable_transcripts' ).check();
		cy.get( '#submit' ).click();

		const options = {
			audioEditLink,
			mediaModalLink,
		}

		// Disable admin role.
		cy.disableFeatureForRoles('speech_to_text', ['administrator'], 'openai_whisper');

		// Verify that the feature is not available.
		cy.verifySpeechToTextEnabled(false, options);

		// Enable admin role.
		cy.enableFeatureForRoles('speech_to_text', ['administrator'], 'openai_whisper');

		// Verify that the feature is available.
		cy.verifySpeechToTextEnabled(true, options);
	} );

	it( 'Can enable/disable speech to text feature by user', () => {
		const options = {
			audioEditLink,
			mediaModalLink,
		}

		// Disable admin role.
		cy.disableFeatureForRoles('speech_to_text', ['administrator'], 'openai_whisper');

		// Verify that the feature is not available.
		cy.verifySpeechToTextEnabled(false, options);

		// Enable feature for admin user.
		cy.enableFeatureForUsers('speech_to_text', ['admin'], 'openai_whisper');

		// Verify that the feature is available.
		cy.verifySpeechToTextEnabled(true, options);
	} );

	it( 'User can opt-out speech to text feature', () => {
		const options = {
			audioEditLink,
			mediaModalLink,
		}

		// Enable user based opt-out.
		cy.enableFeatureOptOut('speech_to_text', 'openai_whisper');

		// opt-out
		cy.optOutFeature('speech_to_text');

		// Verify that the feature is not available.
		cy.verifySpeechToTextEnabled(false, options);

		// opt-in
		cy.optInFeature('speech_to_text');

		// Verify that the feature is available.
		cy.verifySpeechToTextEnabled(true, options);
	} );
} );
