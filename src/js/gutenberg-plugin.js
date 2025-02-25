/* eslint-disable no-unused-vars */
import { ReactComponent as icon } from '../../assets/img/block-icon.svg';
import { handleClick } from './helpers';
import { useSelect, useDispatch, subscribe } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import {
	Button,
	Icon,
	ToggleControl,
	BaseControl,
	Modal,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { useState, useEffect, useRef } from '@wordpress/element';
import { store as postAudioStore } from './store/register';
import TaxonomyControls from './taxonomy-controls';
import PrePubClassifyPost from './gutenberg-plugins/pre-publish-classify-post';
import { DisableFeatureButton } from './components';

const { classifaiPostData, classifaiTTSEnabled } = window;

/**
 * Create the ClassifAI icon
 */
const ClassifAIIcon = () => (
	<Icon className="components-panel__icon" icon={ icon } size={ 24 } />
);

/**
 * ClassificationToggle Component.
 *
 * Used to toggle the classification process on or off.
 */
const ClassificationToggle = () => {
	// Use the datastore to retrieve all the meta for this post.
	const processContent = useSelect( ( select ) =>
		select( 'core/editor' ).getEditedPostAttribute(
			'classifai_process_content'
		)
	);

	// Use the datastore to tell the post to update the meta.
	const { editPost } = useDispatch( 'core/editor' );
	const enabled = 'yes' === processContent ? 'yes' : 'no';

	return (
		<ToggleControl
			label={ __( 'Automatically tag content on update', 'classifai' ) }
			checked={ 'yes' === enabled }
			onChange={ ( value ) => {
				editPost( { classifai_process_content: value ? 'yes' : 'no' } );
			} }
		/>
	);
};

/**
 * Classify button.
 *
 * Used to manually classify the content.
 */
const ClassificationButton = () => {
	const processContent = useSelect( ( select ) =>
		select( 'core/editor' ).getEditedPostAttribute(
			'classifai_process_content'
		)
	);

	const { select, dispatch } = wp.data;
	const postId = select( 'core/editor' ).getCurrentPostId();
	const postType = select( 'core/editor' ).getCurrentPostType();
	const postTypeLabel =
		select( 'core/editor' ).getPostTypeLabel() || __( 'Post', 'classifai' );

	const [ isLoading, setLoading ] = useState( false );
	const [ resultReceived, setResultReceived ] = useState( false );
	const [ isOpen, setOpen ] = useState( false );
	const [ popupOpened, setPopupOpened ] = useState( false );
	const openModal = () => setOpen( true );
	const closeModal = () => setOpen( false );

	const [ taxQuery, setTaxQuery ] = useState( [] );
	const [ featureTaxonomies, setFeatureTaxonomies ] = useState( [] );
	let [ taxTermsAI, setTaxTermsAI ] = useState( [] );

	/**
	 * Callback function to handle API response.
	 *
	 * @param {Object} resp         Response from the API.
	 * @param {Object} callbackArgs Callback arguments.
	 */
	const buttonClickCallBack = async ( resp, callbackArgs ) => {
		if ( resp && resp.terms ) {
			// set feature taxonomies
			if ( resp?.feature_taxonomies ) {
				setFeatureTaxonomies( resp.feature_taxonomies );
			}

			const taxonomies = resp.terms;
			const taxTerms = {};
			const taxTermsExisting = {};

			// get current terms of the post
			const currentTerms = select( 'core' ).getEntityRecord(
				'postType',
				postType,
				postId
			);

			Object.keys( taxonomies ).forEach( ( taxonomy ) => {
				let tax = taxonomy;
				if ( 'post_tag' === taxonomy ) {
					tax = 'tags';
				}
				if ( 'category' === taxonomy ) {
					tax = 'categories';
				}

				const currentTermsOfTaxonomy = currentTerms[ tax ];
				if ( currentTermsOfTaxonomy ) {
					taxTermsExisting[ tax ] = currentTermsOfTaxonomy;
				}

				const newTerms = Object.values( resp.terms[ taxonomy ] );
				if ( newTerms && Object.keys( newTerms ).length ) {
					// Loop through each term and add in taxTermsAI if it does not exist in the post.
					taxTermsAI = taxTermsAI || {};
					Object( newTerms ).forEach( ( termId ) => {
						if ( taxTermsExisting[ tax ] ) {
							const matchedTerm = taxTermsExisting[ tax ].find(
								( termID ) => termID === termId
							);
							if ( ! matchedTerm ) {
								taxTermsAI[ tax ] = taxTermsAI[ tax ] || [];
								// push only if not exist already
								if ( ! taxTermsAI[ tax ].includes( termId ) ) {
									taxTermsAI[ tax ].push( termId );
								}
							}
						}
					} );

					// update the taxTerms
					taxTerms[ tax ] = newTerms;
				}
			} );

			// Merge taxterms with taxTermsExisting and remove duplicates
			Object.keys( taxTermsExisting ).forEach( ( taxonomy ) => {
				if ( taxTerms[ taxonomy ] ) {
					// Merge taxTermsExisting into taxTerms
					taxTerms[ taxonomy ] = taxTerms[ taxonomy ].concat(
						taxTermsExisting[ taxonomy ]
					);
				} else {
					// Initialize taxTerms with taxTermsExisting if not already set
					taxTerms[ taxonomy ] = taxTermsExisting[ taxonomy ];
				}

				// Remove duplicate items from taxTerms
				taxTerms[ taxonomy ] = [ ...new Set( taxTerms[ taxonomy ] ) ];
			} );

			setTaxQuery( taxTerms );
			setTaxTermsAI( taxTermsAI );
		}
		if ( callbackArgs?.openPopup ) {
			openModal();
			setPopupOpened( true );
		}
		setLoading( false );
		setResultReceived( true );
	};

	/**
	 * Save the terms (Modal).
	 *
	 * @param {Object} taxTerms Taxonomy terms.
	 */
	const saveTerms = async ( taxTerms ) => {
		// Remove index values from the nested object
		// Convert the object into an array of key-value pairs
		const taxTermsArray = Object.entries( taxTerms );

		// Remove index values from the nested objects and convert back to an object
		const newtaxTerms = Object.fromEntries(
			taxTermsArray.map( ( [ key, value ] ) => {
				if ( typeof value === 'object' ) {
					return [ key, Object.values( value ) ];
				}
				return [ key, value ];
			} )
		);

		await dispatch( 'core' ).editEntityRecord(
			'postType',
			postType,
			postId,
			newtaxTerms
		);

		// If no edited values in post trigger save.
		const isDirty = await select( 'core/editor' ).isEditedPostDirty();
		if ( ! isDirty ) {
			await dispatch( 'core' ).saveEditedEntityRecord(
				'postType',
				postType,
				postId
			);
		}

		// Display success notice.
		dispatch( 'core/notices' ).createSuccessNotice(
			sprintf(
				/** translators: %s is post type label. */
				__( '%s classified successfully.', 'classifai' ),
				postTypeLabel
			),
			{ type: 'snackbar' }
		);
		closeModal();
	};

	// Display classify post button only when process content on update is disabled.
	const enabled = 'no' === processContent ? 'no' : 'yes';
	if ( 'yes' === enabled ) {
		return null;
	}

	const buttonText = __( 'Suggest terms & tags', 'classifai' );

	let updatedTaxQuery = Object.entries( taxQuery || {} ).reduce(
		( accumulator, [ taxonomySlug, terms ] ) => {
			accumulator[ taxonomySlug ] = terms;

			return accumulator;
		},
		{}
	);

	if ( updatedTaxQuery.taxQuery ) {
		updatedTaxQuery = updatedTaxQuery.taxQuery;
	}

	const modalData = (
		<>
			<TaxonomyControls
				onChange={ ( newTaxQuery ) => {
					setTaxQuery( newTaxQuery );
				} }
				query={ {
					contentPostType: postType,
					featureTaxonomies,
					taxQuery: updatedTaxQuery,
					taxTermsAI: taxTermsAI || {},
					isLoading,
				} }
			/>
			<div className="classifai-modal__footer">
				<div className="classifai-modal__notes">
					{ sprintf(
						/* translators: %s is post type label */
						__(
							'Note that the lists above include any pre-existing terms from this %s.',
							'classifai'
						),
						postTypeLabel
					) }
					<br />
					{ __(
						'AI recommendations saved to this post will not include the "[AI]" text.',
						'classifai'
					) }
				</div>
				<Button
					variant={ 'secondary' }
					onClick={ () => saveTerms( updatedTaxQuery ) }
				>
					{ __( 'Save', 'classifai' ) }
				</Button>
			</div>
			<DisableFeatureButton feature="content_classification" />
		</>
	);

	return (
		<div id="classify-post-component">
			{ isOpen && (
				<Modal
					title={ __( 'Confirm Classification', 'classifai' ) }
					onRequestClose={ closeModal }
					isFullScreen={ false }
					className="classify-modal"
				>
					{ modalData }
				</Modal>
			) }
			<Button
				variant={ 'secondary' }
				data-id={ postId }
				onClick={ ( e ) => {
					handleClick( {
						button: e.target,
						endpoint: '/classifai/v1/classify/',
						callback: buttonClickCallBack,
						callbackArgs: {
							openPopup: true,
						},
						buttonText,
						linkTerms: false,
					} );
				} }
			>
				{ buttonText }
			</Button>
			<span
				className="spinner"
				style={ { display: 'none', float: 'none' } }
			></span>
			<span
				className="error"
				style={ {
					display: 'none',
					color: '#bc0b0b',
					padding: '5px',
				} }
			></span>
			<PrePubClassifyPost popupOpened={ popupOpened }>
				{ ! resultReceived && (
					<>
						<Button
							variant={ 'secondary' }
							data-id={ postId }
							onClick={ ( e ) => {
								handleClick( {
									button: e.target,
									endpoint: '/classifai/v1/classify/',
									callback: buttonClickCallBack,
									buttonText,
									linkTerms: false,
								} );
							} }
						>
							{ buttonText }
						</Button>
						<span
							className="spinner classify"
							style={ { float: 'none', display: 'none' } }
						></span>
						<span
							className="error"
							style={ {
								display: 'none',
								color: '#bc0b0b',
								padding: '5px',
							} }
						></span>
					</>
				) }
				{ resultReceived && modalData }
			</PrePubClassifyPost>
		</div>
	);
};

/**
 * ClassifAI Text to Audio component.
 */
const ClassifAITTS = () => {
	// State of the audio being previewed in PluginDocumentSettingPanel.
	const [ isPreviewing, setIsPreviewing ] = useState( false );

	const [ timestamp, setTimestamp ] = useState( new Date().getTime() );

	// Indicates whether speech synthesis is enabled for the current post.
	const isSynthesizeSpeech = useSelect( ( select ) =>
		select( 'core/editor' ).getEditedPostAttribute(
			'classifai_synthesize_speech'
		)
	);

	// Indicates whether generated audio should be displayed on the frontend.
	const displayGeneratedAudio = useSelect( ( select ) =>
		select( 'core/editor' ).getEditedPostAttribute(
			'classifai_display_generated_audio'
		)
	);

	// Post type label.
	const postTypeLabel = useSelect(
		( select ) =>
			( typeof select( 'core/editor' ).getPostTypeLabel !== 'undefined' &&
				select( 'core/editor' ).getPostTypeLabel() ) ||
			__( 'Post', 'classifai' )
	);

	// Says whether speech synthesis is in progress.
	const isProcessingAudio = useSelect( ( select ) =>
		select( postAudioStore ).getIsProcessing()
	);

	// The audio ID saved in the DB for the current post.
	const defaultAudioId = useSelect( ( select ) =>
		select( 'core/editor' ).getEditedPostAttribute(
			'classifai_post_audio_id'
		)
	);

	// New audio ID in case it is regenerated manually or through publishing/updating the current post.
	const audioId =
		useSelect( ( select ) => select( postAudioStore ).getAudioId() ) ||
		defaultAudioId;

	// Get the attachment data by audio ID.
	const attachments = useSelect( ( select ) =>
		select( 'core' ).getEntityRecords( 'postType', 'attachment', {
			include: [ audioId ],
		} )
	);

	// Get URL for the attachment.
	const sourceUrl =
		attachments && attachments.length > 0 && attachments[ 0 ].source_url;

	const isProcessingAudioProgress = useRef( false );
	const isPostSavingInProgress = useRef( false );
	const { isSavingPost } = useSelect( ( select ) => {
		return {
			isSavingPost: select( 'core/editor' ).isSavingPost(),
		};
	} );
	const { isAutosavingPost } = useSelect( ( select ) => {
		return {
			isSavingPost: select( 'core/editor' ).isAutosavingPost(),
		};
	} );

	// Handles playing/pausing post audio during preview.
	useEffect( () => {
		const audioEl = document.getElementById( 'classifai-audio-preview' );

		if ( ! audioEl ) {
			return;
		}

		if ( isPreviewing ) {
			audioEl.play();
		} else {
			audioEl.pause();
		}
	}, [ isPreviewing ] );

	// Generates a unique timestamp to cache bust audio file.
	useEffect( () => {
		if ( isProcessingAudio ) {
			isProcessingAudioProgress.current = true;
		}

		if ( isProcessingAudioProgress.current && ! isProcessingAudio ) {
			setTimestamp( new Date().getTime() );
		}
	}, [ isProcessingAudio ] );

	useEffect( () => {
		// Code to run during post saving is in process.
		if (
			isSavingPost &&
			! isAutosavingPost &&
			! isPostSavingInProgress.current
		) {
			isPostSavingInProgress.current = true;
			if ( isSynthesizeSpeech ) {
				wp.data.dispatch( postAudioStore ).setIsProcessing( true );
			}
		}

		if (
			! isSavingPost &&
			! isAutosavingPost &&
			isPostSavingInProgress.current
		) {
			// Code to run after post is done saving.
			isPostSavingInProgress.current = false;
			wp.data.dispatch( postAudioStore ).setIsProcessing( false );
		}
	}, [ isSavingPost, isAutosavingPost, isSynthesizeSpeech ] );

	// Fetches the latest audio file to avoid disk cache.
	const cacheBustingUrl = `${ sourceUrl }?ver=${ timestamp }`;

	let audioIcon = 'controls-play';

	if ( isProcessingAudio ) {
		audioIcon = 'format-audio';
	} else if ( isPreviewing ) {
		audioIcon = 'controls-pause';
	}

	return (
		<>
			<ToggleControl
				label={ __( 'Enable audio generation', 'classifai' ) }
				help={ sprintf(
					/** translators: %s is post type label. */
					__(
						'ClassifAI will generate audio for this %s when it is published or updated.',
						'classifai'
					),
					postTypeLabel
				) }
				checked={ isSynthesizeSpeech }
				onChange={ ( value ) => {
					wp.data.dispatch( 'core/editor' ).editPost( {
						classifai_synthesize_speech: value,
					} );
				} }
				disabled={ isProcessingAudio }
				isBusy={ isProcessingAudio }
			/>
			{ sourceUrl && (
				<>
					<ToggleControl
						label={ __( 'Display audio controls', 'classifai' ) }
						help={ __(
							'Controls the display of the audio player on the front-end.',
							'classifai'
						) }
						checked={ displayGeneratedAudio }
						onChange={ ( value ) => {
							wp.data.dispatch( 'core/editor' ).editPost( {
								classifai_display_generated_audio: value,
							} );
						} }
						disabled={ isProcessingAudio }
						isBusy={ isProcessingAudio }
					/>
					<BaseControl
						id="classifai-audio-preview-controls"
						help={
							isProcessingAudio
								? ''
								: __(
										'Preview the generated audio.',
										'classifai'
								  )
						}
					>
						<Button
							id="classifai-audio-controls__preview-btn"
							icon={ <Icon icon={ audioIcon } /> }
							variant="secondary"
							onClick={ () => setIsPreviewing( ! isPreviewing ) }
							disabled={ isProcessingAudio }
							isBusy={ isProcessingAudio }
						>
							{ isProcessingAudio
								? __( 'Generating audio..', 'classifai' )
								: __( 'Preview', 'classifai' ) }
						</Button>
					</BaseControl>
				</>
			) }
			{ sourceUrl && (
				<audio
					id="classifai-audio-preview"
					src={ cacheBustingUrl }
					onEnded={ () => setIsPreviewing( false ) }
				></audio>
			) }
		</>
	);
};

/**
 * Add the ClassifAI panel to Gutenberg
 */
const ClassifAIPlugin = () => {
	const postType = useSelect( ( select ) =>
		select( 'core/editor' ).getCurrentPostType()
	);
	const postStatus = useSelect( ( select ) =>
		select( 'core/editor' ).getCurrentPostAttribute( 'status' )
	);

	// Ensure that at least one feature is enabled.
	const isNLULanguageProcessingEnabled =
		classifaiPostData && classifaiPostData.NLUEnabled;

	// Ensure we are on a supported post type, checking settings from all features.
	const isNLUPostTypeSupported =
		classifaiPostData &&
		classifaiPostData.supportedPostTypes &&
		classifaiPostData.supportedPostTypes.includes( postType );

	// Ensure we are on a supported post status, checking settings from all features.
	const isNLUPostStatusSupported =
		classifaiPostData &&
		classifaiPostData.supportedPostStatues &&
		classifaiPostData.supportedPostStatues.includes( postStatus );

	// Ensure the user has permissions to use the feature.
	const userHasNLUPermissions =
		classifaiPostData &&
		! (
			classifaiPostData.noPermissions &&
			1 === parseInt( classifaiPostData.noPermissions )
		);

	const nluPermissionCheck =
		userHasNLUPermissions &&
		isNLULanguageProcessingEnabled &&
		isNLUPostTypeSupported &&
		isNLUPostStatusSupported;

	return (
		<PluginDocumentSettingPanel
			title={ __( 'ClassifAI', 'classifai' ) }
			icon={ ClassifAIIcon }
			className="classifai-panel"
		>
			<>
				{ nluPermissionCheck && (
					<>
						<ClassificationToggle />
						{ nluPermissionCheck && <ClassificationButton /> }
					</>
				) }
				{ classifaiTTSEnabled && <ClassifAITTS /> }
			</>
		</PluginDocumentSettingPanel>
	);
};

let saveHappened = false;
let showingNotice = false;

subscribe( () => {
	if ( saveHappened === false ) {
		saveHappened = wp.data.select( 'core/editor' ).isSavingPost() === true;
	}

	if (
		saveHappened &&
		wp.data.select( 'core/editor' ).isSavingPost() === false &&
		showingNotice === false
	) {
		const meta = wp.data
			.select( 'core/editor' )
			.getCurrentPostAttribute( 'meta' );
		if ( meta && meta._classifai_text_to_speech_error ) {
			showingNotice = true;
			const error = JSON.parse( meta._classifai_text_to_speech_error );
			wp.data
				.dispatch( 'core/notices' )
				.createErrorNotice(
					`Audio generation failed. Error: ${ error.code } - ${ error.message }`
				);
			saveHappened = false;
			showingNotice = false;
		}
	}
} );

registerPlugin( 'classifai-plugin', { render: ClassifAIPlugin } );
