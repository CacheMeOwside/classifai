<?php

namespace Classifai\Features;

use Classifai\Services\LanguageProcessing;
use Classifai\Providers\Watson\NLU;
use Classifai\Providers\OpenAI\Embeddings;
use WP_REST_Server;
use WP_REST_Request;
use WP_Error;

use function Classifai\get_post_statuses_for_language_settings;
use function Classifai\get_post_types_for_language_settings;
use function Classifai\check_term_permissions;
use function Classifai\get_classification_feature_enabled;
use function Classifai\get_classification_feature_taxonomy;
use function Classifai\get_asset_info;

/**
 * Class Classification
 */
class Classification extends Feature {
	/**
	 * ID of the current feature.
	 *
	 * @var string
	 */
	const ID = 'feature_classification';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->label = __( 'Classification', 'classifai' );

		// Contains all providers that are registered to the service.
		$this->provider_instances = $this->get_provider_instances( LanguageProcessing::get_service_providers() );

		// Contains just the providers this feature supports.
		$this->supported_providers = [
			NLU::ID        => __( 'IBM Watson NLU', 'classifai' ),
			Embeddings::ID => __( 'OpenAI Embeddings', 'classifai' ),
		];
	}

	/**
	 * Set up necessary hooks.
	 *
	 * We utilize this so we can register the REST route.
	 */
	public function setup() {
		parent::setup();
		add_action( 'rest_api_init', [ $this, 'register_endpoints' ] );
	}

	/**
	 * Set up necessary hooks.
	 */
	public function feature_setup() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'classifai_after_feature_settings_form', [ $this, 'render_previewer' ] );
		add_action( 'rest_api_init', [ $this, 'add_process_content_meta_to_rest_api' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ], 10, 2 );
		add_action( 'save_post', [ $this, 'save_metabox' ] );
	}

	/**
	 * Register any needed endpoints.
	 */
	public function register_endpoints() {
		$post_types = $this->get_supported_post_types();
		foreach ( $post_types as $post_type ) {
			register_meta(
				$post_type,
				'_classifai_error',
				[
					'show_in_rest'  => true,
					'single'        => true,
					'auth_callback' => '__return_true',
				]
			);
		}

		register_rest_route(
			'classifai/v1',
			'classify/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_endpoint_callback' ],
				'args'                => array(
					'id'        => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => esc_html__( 'Post ID to classify.', 'classifai' ),
					),
					'linkTerms' => array(
						'type'        => 'boolean',
						'description' => esc_html__( 'Whether to link terms or not.', 'classifai' ),
						'default'     => true,
					),
				),
				'permission_callback' => [ $this, 'classify_permissions_check' ],
			]
		);
	}

	/**
	 * Check if a given request has access to run classification.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function classify_permissions_check( WP_REST_Request $request ) {
		$post_id = $request->get_param( 'id' );

		// Ensure we have a logged in user that can edit the item.
		if ( empty( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$post_type     = get_post_type( $post_id );
		$post_type_obj = get_post_type_object( $post_type );

		// Ensure the post type is allowed in REST endpoints.
		if ( ! $post_type || empty( $post_type_obj ) || empty( $post_type_obj->show_in_rest ) ) {
			return false;
		}

		// For all enabled features, ensure the user has proper permissions to add/edit terms.
		$provider_instance = $this->get_feature_provider_instance();
		if ( empty( $provider_instance->nlu_features ) ) {
			return new WP_Error( 'not_enabled', esc_html__( 'Classification not configured correctly for the selected provider.', 'classifai' ) );
		}

		foreach ( $provider_instance->nlu_features as $feature_name => $feature ) {
			if ( ! get_classification_feature_enabled( $feature_name ) ) {
				continue;
			}

			$taxonomy   = get_classification_feature_taxonomy( $feature_name );
			$permission = check_term_permissions( $taxonomy );

			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
		}

		$post_status   = get_post_status( $post_id );
		$supported     = $this->get_supported_post_types();
		$post_statuses = $this->get_supported_post_statuses();

		// Check if processing allowed.
		if (
			! in_array( $post_status, $post_statuses, true ) ||
			! in_array( $post_type, $supported, true ) ||
			! $this->is_feature_enabled()
		) {
			return new WP_Error( 'not_enabled', esc_html__( 'Classification not enabled for current item.', 'classifai' ) );
		}

		return true;
	}

	/**
	 * Generic request handler for all our custom routes.
	 *
	 * @param WP_REST_Request $request The full request object.
	 * @return \WP_REST_Response
	 */
	public function rest_endpoint_callback( WP_REST_Request $request ) {
		$route = $request->get_route();

		if ( strpos( $route, '/classifai/v1/classify' ) === 0 ) {
			$results = $this->run(
				$request->get_param( 'id' ),
				'classify',
				[
					'link_terms' => $request->get_param( 'linkTerms' ),
				]
			);

			return rest_ensure_response(
				[
					'terms'              => $results,
					'feature_taxonomies' => $this->get_all_feature_taxonomies(),
				]
			);
		}

		return parent::rest_endpoint_callback( $request );
	}

	/**
	 * Enqueue the admin scripts.
	 */
	public function enqueue_admin_assets() {
		wp_enqueue_script(
			'classifai-language-processing-script',
			CLASSIFAI_PLUGIN_URL . 'dist/language-processing.js',
			get_asset_info( 'language-processing', 'dependencies' ),
			get_asset_info( 'language-processing', 'version' ),
			true
		);

		wp_enqueue_style(
			'classifai-language-processing-style',
			CLASSIFAI_PLUGIN_URL . 'dist/language-processing.css',
			array(),
			get_asset_info( 'language-processing', 'version' ),
			'all'
		);
	}

	/**
	 * Enqueue editor assets.
	 */
	public function enqueue_editor_assets() {
		global $post;

		wp_enqueue_script(
			'classifai-editor',
			CLASSIFAI_PLUGIN_URL . 'dist/editor.js',
			get_asset_info( 'editor', 'dependencies' ),
			get_asset_info( 'editor', 'version' ),
			true
		);

		if ( empty( $post ) ) {
			return;
		}

		wp_enqueue_script(
			'classifai-gutenberg-plugin',
			CLASSIFAI_PLUGIN_URL . 'dist/gutenberg-plugin.js',
			array_merge( get_asset_info( 'gutenberg-plugin', 'dependencies' ), array( 'lodash' ) ),
			get_asset_info( 'gutenberg-plugin', 'version' ),
			true
		);

		// TODO: check JS var classifaiEmbeddingData

		wp_add_inline_script(
			'classifai-gutenberg-plugin',
			sprintf(
				'var classifaiPostData = %s;',
				wp_json_encode(
					[
						'NLUEnabled'           => $this->is_feature_enabled(),
						'supportedPostTypes'   => $this->get_supported_post_types(),
						'supportedPostStatues' => $this->get_supported_post_statuses(),
						'noPermissions'        => ! is_user_logged_in() || ! current_user_can( 'edit_post', $post->ID ),
					]
				)
			),
			'before'
		);
	}

	/**
	 * Add `classifai_process_content` to the REST API for view/edit.
	 */
	public function add_process_content_meta_to_rest_api() {
		$supported_post_types = $this->get_supported_post_types();

		register_rest_field(
			$supported_post_types,
			'classifai_process_content',
			[
				'get_callback'    => function ( $data ) {
					$process_content = get_post_meta( $data['id'], '_classifai_process_content', true );
					return ( 'no' === $process_content ) ? 'no' : 'yes';
				},
				'update_callback' => function ( $value, $data ) {
					$value = ( 'no' === $value ) ? 'no' : 'yes';
					return update_post_meta( $data->ID, '_classifai_process_content', $value );
				},
				'schema'          => [
					'type'    => 'string',
					'context' => [ 'view', 'edit' ],
				],
			]
		);
	}

	/**
	 * Add metabox to enable/disable language processing.
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post WP_Post object.
	 */
	public function add_meta_box( string $post_type, \WP_Post $post ) {
		$supported_post_types = $this->get_supported_post_types();
		$post_statuses        = $this->get_supported_post_statuses();
		$post_status          = get_post_status( $post );

		if (
			in_array( $post_type, $supported_post_types, true ) &&
			in_array( $post_status, $post_statuses, true )
		) {
			add_meta_box(
				'classifai_language_processing_metabox',
				__( 'ClassifAI Language Processing', 'classifai' ),
				[ $this, 'render_meta_box' ],
				null,
				'side',
				'high',
				array( '__back_compat_meta_box' => true )
			);
		}
	}

	/**
	 * Render metabox content.
	 *
	 * @param \WP_Post $post WP_Post object.
	 */
	public function render_meta_box( \WP_Post $post ) {
		wp_nonce_field( 'classifai_language_processing_meta_action', 'classifai_language_processing_meta' );
		$process_content = get_post_meta( $post->ID, '_classifai_process_content', true );
		$process_content = ( 'no' === $process_content ) ? 'no' : 'yes';
		?>

		<p>
			<label for="classifai-process-content">
				<input type="checkbox" value="yes" name="_classifai_process_content" id="classifai-process-content" <?php checked( $process_content, 'yes' ); ?> />
				<?php esc_html_e( 'Automatically tag content on update', 'classifai' ); ?>
			</label>
		</p>

		<div class="classifai-clasify-post-wrapper" style="display: none;">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=classifai_classify_post&post_id=' . $post->ID ), 'classifai_classify_post_action', 'classifai_classify_post_nonce' ) ); ?>" class="button button-classify-post">
				<?php esc_html_e( 'Suggest terms & tags', 'classifai' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Handles saving the metabox.
	 *
	 * @param int $post_id Current post ID.
	 */
	public function save_metabox( int $post_id ) {
		if (
			wp_is_post_autosave( $post_id ) ||
			wp_is_post_revision( $post_id ) ||
			! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		if (
			empty( $_POST['classifai_language_processing_meta'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['classifai_language_processing_meta'] ) ), 'classifai_language_processing_meta_action' )
		) {
			return;
		}

		$classifai_process_content = isset( $_POST['_classifai_process_content'] ) ? sanitize_key( wp_unslash( $_POST['_classifai_process_content'] ) ) : '';

		if ( 'yes' !== $classifai_process_content ) {
			update_post_meta( $post_id, '_classifai_process_content', 'no' );
		} else {
			update_post_meta( $post_id, '_classifai_process_content', 'yes' );
		}
	}

	/**
	 * Renders the previewer window for the feature.
	 *
	 * @param string $active_feature The active feature.
	 */
	public function render_previewer( string $active_feature ) {
		if ( self::ID !== $active_feature || ! $this->is_feature_enabled() ) {
			return;
		}

		$provider_instance       = $this->get_feature_provider_instance();
		$nlu_features            = array();
		$supported_post_statuses = $this->get_supported_post_statuses();
		$supported_post_types    = $this->get_supported_post_types();

		$posts_to_preview = get_posts(
			array(
				'post_type'      => $supported_post_types,
				'post_status'    => $supported_post_statuses,
				'posts_per_page' => 10,
			)
		);

		if ( ! empty( $provider_instance->nlu_features ) ) {
			$nlu_features = $provider_instance->nlu_features;
		}
		?>

		<div id="classifai-post-preview-app">
			<h2><?php esc_html_e( 'Preview Language Processing', 'classifai' ); ?></h2>

			<div id="classifai-post-preview-controls">
				<select id="classifai-preview-post-selector">
					<?php foreach ( $posts_to_preview as $post ) : ?>
						<option value="<?php echo esc_attr( $post->ID ); ?>"><?php echo esc_html( $post->post_title ); ?></option>
					<?php endforeach; ?>
				</select>

				<?php wp_nonce_field( 'classifai-previewer-action', 'classifai-previewer-nonce' ); ?>

				<button type="button" class="button" id="get-classifier-preview-data-btn">
					<span><?php esc_html_e( 'Preview', 'classifai' ); ?></span>
				</button>
			</div>

			<div id="classifai-post-preview-wrapper">
				<?php
				foreach ( $nlu_features as $feature_slug => $feature ) :
					if ( ! get_classification_feature_enabled( $feature_slug ) ) {
						continue;
					}
					?>

					<div class="tax-row tax-row--<?php echo esc_attr( $feature_slug ); ?>">
						<div class="tax-type"><?php echo esc_html( $feature['feature'] ); ?></div>
					</div>

					<?php
				endforeach;
				?>
			</div>
		</div>

		<?php
	}

	/**
	 * Get the description for the enable field.
	 *
	 * @return string
	 */
	public function get_enable_description(): string {
		return esc_html__( 'Enables automatic content classification.', 'classifai' );
	}

	/**
	 * Add any needed custom fields.
	 */
	public function add_custom_settings_fields() {
		$settings          = $this->get_settings();
		$provider_instance = $this->get_feature_provider_instance();
		$nlu_features      = array();
		$post_statuses     = get_post_statuses_for_language_settings();
		$post_types        = get_post_types_for_language_settings();
		$post_type_options = array();

		if ( ! empty( $provider_instance->nlu_features ) ) {
			$nlu_features = $provider_instance->nlu_features;
		}

		foreach ( $post_types as $post_type ) {
			$post_type_options[ $post_type->name ] = $post_type->label;
		}

		add_settings_field(
			'classification_mode',
			esc_html__( 'Classification mode', 'classifai' ),
			[ $this, 'render_radio_group' ],
			$this->get_option_name(),
			$this->get_option_name() . '_section',
			[
				'label_for'     => 'classification_mode',
				'default_value' => $settings['classification_mode'],
				'options'       => array(
					'manual_review'            => __( 'Manual review', 'classifai' ),
					'automatic_classification' => __( 'Automatic classification', 'classifai' ),
				),
			]
		);

		add_settings_field(
			'classification_method',
			esc_html__( 'Classification method', 'classifai' ),
			[ $this, 'render_radio_group' ],
			$this->get_option_name(),
			$this->get_option_name() . '_section',
			[
				'label_for'     => 'classification_method',
				'default_value' => $settings['classification_method'],
				'options'       => array(
					'recommended_terms' => __( 'Recommend terms even if they do not exist on the site', 'classifai' ),
					'existing_terms'    => __( 'Only recommend terms that already exist on the site', 'classifai' ),
				),
			]
		);

		foreach ( $nlu_features as $classify_by => $labels ) {
			add_settings_field(
				$classify_by,
				esc_html( $labels['feature'] ),
				[ $this, 'render_nlu_feature_settings' ],
				$this->get_option_name(),
				$this->get_option_name() . '_section',
				[
					'feature'       => $classify_by,
					'labels'        => $labels,
					'default_value' => $settings[ $classify_by ],
				]
			);
		}

		add_settings_field(
			'post_statuses',
			esc_html__( 'Post statuses', 'classifai' ),
			[ $this, 'render_checkbox_group' ],
			$this->get_option_name(),
			$this->get_option_name() . '_section',
			[
				'label_for'      => 'post_statuses',
				'options'        => $post_statuses,
				'default_values' => $settings['post_statuses'],
				'description'    => __( 'Choose which post statuses are allowed to use this feature.', 'classifai' ),
			]
		);

		add_settings_field(
			'post_types',
			esc_html__( 'Post types', 'classifai' ),
			[ $this, 'render_checkbox_group' ],
			$this->get_option_name(),
			$this->get_option_name() . '_section',
			[
				'label_for'      => 'post_types',
				'options'        => $post_type_options,
				'default_values' => $settings['post_types'],
				'description'    => __( 'Choose which post types are allowed to use this feature.', 'classifai' ),
			]
		);
	}

	/**
	 * Returns the default settings for the feature.
	 *
	 * @return array
	 */
	public function get_feature_default_settings(): array {
		return [
			'post_statuses'         => [
				'publish' => 1,
			],
			'post_types'            => [
				'post' => 1,
			],
			'classification_mode'   => 'manual_review',
			'classification_method' => 'recommended_terms',
			'provider'              => NLU::ID,
		];
	}

	/**
	 * Sanitizes the default feature settings.
	 *
	 * @param array $new_settings Settings being saved.
	 * @return array
	 */
	public function sanitize_default_feature_settings( array $new_settings ): array {
		$settings          = $this->get_settings();
		$provider_instance = $this->get_feature_provider_instance();

		$new_settings['classification_mode'] = sanitize_text_field( $new_settings['classification_mode'] ?? $settings['classification_mode'] );

		$new_settings['classification_method'] = sanitize_text_field( $new_settings['classification_method'] ?? $settings['classification_method'] );

		$new_settings['post_statuses'] = isset( $new_settings['post_statuses'] ) ? array_map( 'sanitize_text_field', $new_settings['post_statuses'] ) : $settings['post_statuses'];

		$new_settings['post_types'] = isset( $new_settings['post_types'] ) ? array_map( 'sanitize_text_field', $new_settings['post_types'] ) : $settings['post_types'];

		if ( ! empty( $provider_instance->nlu_features ) ) {
			foreach ( $provider_instance->nlu_features as $feature_name => $feature ) {
				$new_settings[ $feature_name ]               = absint( $new_settings[ $feature_name ] ?? $settings[ $feature_name ] );
				$new_settings[ "{$feature_name}_threshold" ] = absint( $new_settings[ "{$feature_name}_threshold" ] ?? $settings[ "{$feature_name}_threshold" ] );
				$new_settings[ "{$feature_name}_taxonomy" ]  = sanitize_text_field( $new_settings[ "{$feature_name}_taxonomy" ] ?? $settings[ "{$feature_name}_taxonomy" ] );
			}
		}

		return $new_settings;
	}

	/**
	 * Runs the feature.
	 *
	 * @param mixed ...$args Arguments required by the feature depending on the provider selected.
	 * @return mixed
	 */
	public function run( ...$args ) {
		$settings          = $this->get_settings();
		$provider_id       = $settings['provider'] ?? NLU::ID;
		$provider_instance = $this->get_feature_provider_instance( $provider_id );
		$result            = '';

		if ( NLU::ID === $provider_instance::ID ) {
			/** @var NLU $provider_instance */
			$result = call_user_func_array(
				[ $provider_instance, 'classify' ],
				[ ...$args ]
			);
		} elseif ( Embeddings::ID === $provider_instance::ID ) {
			/** @var Embeddings $provider_instance */
			$result = call_user_func_array(
				[ $provider_instance, 'generate_embeddings_for_post' ],
				[ ...$args ]
			);
		}

		return apply_filters(
			'classifai_' . static::ID . '_run',
			$result,
			$provider_instance,
			$args,
			$this
		);
	}

	/**
	 * The list of post types that support classification.
	 *
	 * @return array
	 */
	public function get_supported_post_types(): array {
		$settings   = $this->get_settings();
		$post_types = [];

		foreach ( $settings['post_types'] as $post_type => $enabled ) {
			if ( ! empty( $enabled ) ) {
				$post_types[] = $post_type;
			}
		}

		/**
		 * Filter post types supported for classification.
		 *
		 * @since 3.0.0
		 * @hook classifai_feature_classification_post_types
		 *
		 * @param {array} $post_types Array of post types to be classified.
		 *
		 * @return {array} Array of post types.
		 */
		$post_types = apply_filters( 'classifai_' . static::ID . '_post_types', $post_types );

		return $post_types;
	}

	/**
	 * The list of post statuses that support classification.
	 *
	 * @return array
	 */
	public function get_supported_post_statuses(): array {
		$settings      = $this->get_settings();
		$post_statuses = [];

		foreach ( $settings['post_statuses'] as $post_status => $enabled ) {
			if ( ! empty( $enabled ) ) {
				$post_statuses[] = $post_status;
			}
		}

		/**
		 * Filter post statuses supported for classification.
		 *
		 * @since 3.0.0
		 * @hook classifai_feature_classification_post_statuses
		 *
		 * @param {array} $post_types Array of post statuses to be classified.
		 *
		 * @return {array} Array of post statuses.
		 */
		$post_statuses = apply_filters( 'classifai_' . static::ID . '_post_statuses', $post_statuses );

		return $post_statuses;
	}

	/**
	 * Get all feature taxonomies.
	 *
	 * @return array
	 */
	public function get_all_feature_taxonomies(): array {
		$feature_taxonomies = [];
		$provider_instance  = $this->get_feature_provider_instance();

		if ( empty( $provider_instance->nlu_features ) ) {
			return $feature_taxonomies;
		}

		foreach ( $provider_instance->nlu_features as $feature_name => $feature ) {
			if ( ! get_classification_feature_enabled( $feature_name ) ) {
				continue;
			}

			$taxonomy   = get_classification_feature_taxonomy( $feature_name );
			$permission = check_term_permissions( $taxonomy );

			if ( is_wp_error( $permission ) ) {
				continue;
			}

			if ( 'post_tag' === $taxonomy ) {
				$taxonomy = 'tags';
			}

			if ( 'category' === $taxonomy ) {
				$taxonomy = 'categories';
			}

			$feature_taxonomies[] = $taxonomy;
		}

		return $feature_taxonomies;
	}

	/**
	 * Render the NLU features settings.
	 *
	 * @param array $args Settings for the inputs
	 */
	public function render_nlu_feature_settings( array $args ) {
		$feature = $args['feature'];
		$labels  = $args['labels'];

		$taxonomies = $this->get_supported_taxonomies();
		$features   = $this->get_settings();
		$taxonomy   = isset( $features[ "{$feature}_taxonomy" ] ) ? $features[ "{$feature}_taxonomy" ] : $labels['taxonomy_default'];

		// Enable classification type
		$feature_args = [
			'label_for'  => $feature,
			'input_type' => 'checkbox',
		];

		$threshold_args = [
			'label_for'     => "{$feature}_threshold",
			'input_type'    => 'number',
			'default_value' => $labels['threshold_default'],
		];
		?>

		<legend class="screen-reader-text">
			<?php esc_html_e( 'Classification Taxonomy Settings', 'classifai' ); ?>
		</legend>

		<p>
			<?php $this->render_input( $feature_args ); ?>
			<label for="classifai-settings-<?php echo esc_attr( $feature ); ?>">
				<?php esc_html_e( 'Enable', 'classifai' ); ?>
			</label>
		</p>

		<p>
			<label for="classifai-settings-<?php echo esc_attr( "{$feature}_threshold" ); ?>">
				<?php echo esc_html( $labels['threshold'] ); ?>
			</label><br/>
			<?php $this->render_input( $threshold_args ); ?>
		</p>

		<?php if ( NLU::ID === $features['provider'] ) : ?>
		<p>
			<label for="classifai-settings-<?php echo esc_attr( "{$feature}_taxonomy" ); ?>"><?php echo esc_html( $labels['taxonomy'] ); ?></label><br/>
			<select id="classifai-settings-<?php echo esc_attr( "{$feature}_taxonomy" ); ?>" name="<?php echo esc_attr( $this->get_option_name() ); ?>[<?php echo esc_attr( self::ID ); ?>][<?php echo esc_attr( "{$feature}_taxonomy" ); ?>]">
				<?php foreach ( $taxonomies as $name => $singular_name ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $taxonomy, esc_attr( $name ) ); ?>>
						<?php echo esc_html( $singular_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Return the list of supported taxonomies
	 *
	 * @return array
	 */
	public function get_supported_taxonomies(): array {
		$taxonomies = get_taxonomies( [], 'objects' );
		$taxonomies = array_filter( $taxonomies, 'is_taxonomy_viewable' );
		$supported  = [];

		foreach ( $taxonomies as $taxonomy ) {
			$supported[ $taxonomy->name ] = $taxonomy->labels->singular_name;
		}

		/**
		 * Filter taxonomies shown in settings.
		 *
		 * @since 3.0.0
		 * @hook classifai_feature_classification_setting_taxonomies
		 *
		 * @param {array} $supported Array of supported taxonomies.
		 * @param {object} $this Current instance of the class.
		 *
		 * @return {array} Array of taxonomies.
		 */
		return apply_filters( 'classifai_' . static::ID . '_setting_taxonomies', $supported, $this );
	}
}
