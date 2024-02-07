<?php
/**
 * IBM Watson NLU
 */

namespace Classifai\Providers\Watson;

use Classifai\Providers\Provider;
use Classifai\Taxonomy\TaxonomyFactory;
use Classifai\Features\Classification;
use Classifai\Features\Feature;
use Classifai\Providers\Watson\PostClassifier;
use WP_Error;

class NLU extends Provider {

	const ID = 'ibm_watson_nlu';

	/**
	 * @var $taxonomy_factory TaxonomyFactory Watson taxonomy factory
	 */
	public $taxonomy_factory;

	/**
	 * @var $save_post_handler SavePostHandler Triggers a classification with Watson
	 */
	public $save_post_handler;

	/**
	 * NLU features that are supported by this provider
	 *
	 * @var array
	 */
	public $nlu_features = [];

	/**
	 * Watson NLU constructor.
	 *
	 * @param \Classifai\Features\Feature $feature Feature instance (Optional, only required in admin).
	 */
	public function __construct( $feature = null ) {
		$this->feature_instance = $feature;

		$this->nlu_features = [
			'category' => [
				'feature'           => __( 'Category', 'classifai' ),
				'threshold'         => __( 'Category Threshold (%)', 'classifai' ),
				'taxonomy'          => __( 'Category Taxonomy', 'classifai' ),
				'threshold_default' => WATSON_CATEGORY_THRESHOLD,
				'taxonomy_default'  => WATSON_CATEGORY_TAXONOMY,
			],
			'keyword'  => [
				'feature'           => __( 'Keyword', 'classifai' ),
				'threshold'         => __( 'Keyword Threshold (%)', 'classifai' ),
				'taxonomy'          => __( 'Keyword Taxonomy', 'classifai' ),
				'threshold_default' => WATSON_KEYWORD_THRESHOLD,
				'taxonomy_default'  => WATSON_KEYWORD_TAXONOMY,
			],
			'entity'   => [
				'feature'           => __( 'Entity', 'classifai' ),
				'threshold'         => __( 'Entity Threshold (%)', 'classifai' ),
				'taxonomy'          => __( 'Entity Taxonomy', 'classifai' ),
				'threshold_default' => WATSON_ENTITY_THRESHOLD,
				'taxonomy_default'  => WATSON_ENTITY_TAXONOMY,
			],
			'concept'  => [
				'feature'           => __( 'Concept', 'classifai' ),
				'threshold'         => __( 'Concept Threshold (%)', 'classifai' ),
				'taxonomy'          => __( 'Concept Taxonomy', 'classifai' ),
				'threshold_default' => WATSON_CONCEPT_THRESHOLD,
				'taxonomy_default'  => WATSON_CONCEPT_TAXONOMY,
			],
		];
	}

	/**
	 * Renders settings fields for this provider.
	 */
	public function render_provider_fields() {
		$settings = $this->feature_instance->get_settings( static::ID );

		add_settings_field(
			static::ID . '_endpoint_url',
			esc_html__( 'API URL', 'classifai' ),
			[ $this->feature_instance, 'render_input' ],
			$this->feature_instance->get_option_name(),
			$this->feature_instance->get_option_name() . '_section',
			[
				'option_index'  => static::ID,
				'label_for'     => 'endpoint_url',
				'default_value' => $settings['endpoint_url'],
				'input_type'    => 'text',
				'large'         => true,
				'class'         => 'classifai-provider-field hidden provider-scope-' . static::ID, // Important to add this.
			]
		);

		add_settings_field(
			static::ID . '_username',
			esc_html__( 'API Username', 'classifai' ),
			[ $this->feature_instance, 'render_input' ],
			$this->feature_instance->get_option_name(),
			$this->feature_instance->get_option_name() . '_section',
			[
				'option_index'  => static::ID,
				'label_for'     => 'username',
				'default_value' => $settings['username'],
				'input_type'    => 'text',
				'large'         => true,
				'class'         => 'classifai-provider-field ' . ( $this->use_username_password() ? 'hide-username' : '' ) . ' provider-scope-' . static::ID, // Important to add this.
			]
		);

		add_settings_field(
			static::ID . '_password',
			esc_html__( 'API Key', 'classifai' ),
			[ $this->feature_instance, 'render_input' ],
			$this->feature_instance->get_option_name(),
			$this->feature_instance->get_option_name() . '_section',
			[
				'option_index'  => static::ID,
				'label_for'     => 'password',
				'default_value' => $settings['password'],
				'input_type'    => 'password',
				'large'         => true,
				'class'         => 'classifai-provider-field provider-scope-' . static::ID, // Important to add this.
				'description'   => sprintf(
					wp_kses(
						/* translators: %1$s is the link to register for an IBM Cloud account, %2$s is the link to setup the NLU service */
						__( 'Don\'t have an IBM Cloud account yet? <a title="Register for an IBM Cloud account" href="%1$s">Register for one</a> and set up a <a href="%2$s">Natural Language Understanding</a> Resource to get your API key.', 'classifai' ),
						[
							'a' => [
								'href'  => [],
								'title' => [],
							],
						]
					),
					esc_url( 'https://cloud.ibm.com/registration' ),
					esc_url( 'https://cloud.ibm.com/catalog/services/natural-language-understanding' )
				),
			]
		);

		add_settings_field(
			static::ID . '_toggle',
			'',
			function ( $args = [] ) {
				printf(
					'<a id="classifai-waston-cred-toggle" href="#" class="%s">%s</a>',
					$args['class'] ? esc_attr( $args['class'] ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$this->use_username_password() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						? esc_html__( 'Use a username/password instead?', 'classifai' )
						: esc_html__( 'Use an API Key instead?', 'classifai' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			},
			$this->feature_instance->get_option_name(),
			$this->feature_instance->get_option_name() . '_section',
			[
				'class' => 'classifai-provider-field hidden provider-scope-' . static::ID, // Important to add this.
			]
		);

		do_action( 'classifai_' . static::ID . '_render_provider_fields', $this );
	}

	/**
	 * Modify the default settings for the classification feature.
	 *
	 * @param array   $settings Current settings.
	 * @param Feature $feature_instance The feature instance.
	 * @return array
	 */
	public function modify_default_feature_settings( array $settings, $feature_instance ): array {
		remove_filter( 'classifai_feature_classification_get_default_settings', [ $this, 'modify_default_feature_settings' ], 10, 2 );

		if ( $feature_instance->get_settings( 'provider' ) !== static::ID ) {
			return $settings;
		}

		add_filter( 'classifai_feature_classification_get_default_settings', [ $this, 'modify_default_feature_settings' ], 10, 2 );

		return array_merge(
			$settings,
			[
				'category'           => true,
				'category_threshold' => WATSON_CATEGORY_THRESHOLD,
				'category_taxonomy'  => WATSON_CATEGORY_TAXONOMY,

				'keyword'            => true,
				'keyword_threshold'  => WATSON_KEYWORD_THRESHOLD,
				'keyword_taxonomy'   => WATSON_KEYWORD_TAXONOMY,

				'concept'            => false,
				'concept_threshold'  => WATSON_CONCEPT_THRESHOLD,
				'concept_taxonomy'   => WATSON_CONCEPT_TAXONOMY,

				'entity'             => false,
				'entity_threshold'   => WATSON_ENTITY_THRESHOLD,
				'entity_taxonomy'    => WATSON_ENTITY_TAXONOMY,
			]
		);
	}

	/**
	 * Returns the default settings for this provider.
	 *
	 * @return array
	 */
	public function get_default_provider_settings(): array {
		$common_settings = [
			'endpoint_url' => '',
			'apikey'       => '',
			'username'     => '',
			'password'     => '',
		];

		return $common_settings;
	}

	/**
	 * Register what we need for the plugin.
	 */
	public function register() {
		add_filter( 'classifai_feature_classification_get_default_settings', [ $this, 'modify_default_feature_settings' ], 10, 2 );

		$feature = new Classification();

		if (
			$feature->is_feature_enabled() &&
			$feature->get_feature_provider_instance()::ID === static::ID
		) {

			$this->taxonomy_factory = new TaxonomyFactory();
			$this->taxonomy_factory->build_all();

	// 		$this->save_post_handler = new SavePostHandler();
	// 		$this->save_post_handler->register();

	// 		new PreviewClassifierData();
		}
	}

	/**
	 * Check if a username/password is used instead of API key.
	 *
	 * @return bool
	 */
	protected function use_username_password(): bool {
		$feature  = new Classification();
		$settings = $feature->get_settings( static::ID );

		if ( empty( $settings['username'] ) ) {
			return false;
		}

		return 'apikey' === $settings['username'];
	}

	/**
	 * Helper to ensure the authentication works.
	 *
	 * @param array $settings The list of settings to be saved
	 * @return bool|WP_Error
	 */
	protected function nlu_authentication_check( array $settings ) {
		// Check that we have credentials before hitting the API.
		if ( empty( $settings[ static::ID ]['username'] )
			|| empty( $settings[ static::ID ]['password'] )
			|| empty( $settings[ static::ID ]['endpoint_url'] )
		) {
			return new WP_Error( 'auth', esc_html__( 'Please enter your credentials.', 'classifai' ) );
		}

		$request           = new APIRequest();
		$request->username = $settings[ static::ID ]['username'];
		$request->password = $settings[ static::ID ]['password'];
		$base_url          = trailingslashit( $settings[ static::ID ]['endpoint_url'] ) . 'v1/analyze';
		$url               = esc_url( add_query_arg( [ 'version' => WATSON_NLU_VERSION ], $base_url ) );
		$options           = [
			'body' => wp_json_encode(
				[
					'text'     => 'Lorem ipsum dolor sit amet.',
					'language' => 'en',
					'features' => [
						'keywords' => [
							'emotion' => false,
							'limit'   => 1,
						],
					],
				]
			),
		];

		$response = $request->post( $url, $options );

		if ( ! is_wp_error( $response ) ) {
			update_option( 'classifai_configured', true );
			return true;
		} else {
			delete_option( 'classifai_configured' );
			return $response;
		}
	}

	/**
	 * Sanitization for the options being saved.
	 *
	 * @param array $new_settings Array of settings about to be saved.
	 * @return array The sanitized settings to be saved.
	 */
	public function sanitize_settings( array $new_settings ): array {
		$settings      = $this->feature_instance->get_settings();
		$authenticated = $this->nlu_authentication_check( $new_settings );

		if ( is_wp_error( $authenticated ) ) {
			$new_settings[ static::ID ]['authenticated'] = false;
			add_settings_error(
				'classifai-credentials',
				'classifai-auth',
				$authenticated->get_error_message(),
				'error'
			);
		} else {
			$new_settings[ static::ID ]['authenticated'] = true;
		}

		$new_settings[ static::ID ]['endpoint_url'] = esc_url_raw( $new_settings[ static::ID ]['endpoint_url'] ?? $settings[ static::ID ]['endpoint_url'] );
		$new_settings[ static::ID ]['username']     = sanitize_text_field( $new_settings[ static::ID ]['username'] ?? $settings[ static::ID ]['username'] );
		$new_settings[ static::ID ]['password']     = sanitize_text_field( $new_settings[ static::ID ]['password'] ?? $settings[ static::ID ]['password'] );

		return $new_settings;
	}

	/**
	 * Format the result of most recent request.
	 *
	 * @param array|WP_Error $data Response data to format.
	 * @return string
	 */
	protected function get_formatted_latest_response( $data ): string {
		if ( ! $data ) {
			return __( 'N/A', 'classifai' );
		}

		if ( is_wp_error( $data ) ) {
			return $data->get_error_message();
		}

		$formatted_data = array_intersect_key(
			$data,
			[
				'usage'    => 1,
				'language' => 1,
			]
		);

		foreach ( array_diff_key( $data, $formatted_data ) as $key => $value ) {
			$formatted_data[ $key ] = count( $value );
		}

		return preg_replace( '/,"/', ', "', wp_json_encode( $formatted_data ) );
	}

	/**
	 * Common entry point for all REST endpoints for this provider.
	 * This is called by the Service.
	 *
	 * @param int    $post_id The Post Id we're processing.
	 * @param string $route_to_call The route we are processing.
	 * @param array  $args Optional arguments to pass to the route.
	 * @return string|WP_Error
	 */
	public function rest_endpoint_callback( $post_id = 0, string $route_to_call = '', array $args = [] ) {
		$route_to_call = strtolower( $route_to_call );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'post_id_required', esc_html__( 'A valid post ID is required to generate an excerpt.', 'classifai' ) );
		}

		$return = '';

		// Handle all of our routes.
		switch ( $route_to_call ) {
			case 'classify':
				$return = ( new Classification() )->run( $post_id, $args['link_terms'] ?? true );
				break;
		}

		return $return;
	}

	/**
	 * Handle request to generate tags for given post ID.
	 *
	 * @param int $post_id The Post Id we're processing.
	 * @return mixed
	 */
	public function classify_post( int $post_id ) {
		try {
			if ( empty( $post_id ) ) {
				return new WP_Error( 'post_id_required', esc_html__( 'Post ID is required to classify post.', 'classifai' ) );
			}

			$taxonomy_terms = [];
			$features       = [ 'category', 'keyword', 'concept', 'entity' ];

			// Process post content.
			$result = $this->classify( $post_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $features as $feature ) {
				$taxonomy = get_feature_taxonomy( $feature );
				$terms    = wp_get_object_terms( $post_id, $taxonomy );
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$taxonomy_terms[ $taxonomy ][] = $term->term_id;
					}
				}
			}

			// Return taxonomy terms.
			return rest_ensure_response( [ 'terms' => $taxonomy_terms ] );
		} catch ( \Exception $e ) {
			return new WP_Error( 'request_failed', $e->getMessage() );
		}
	}

	/**
	 * Classifies the post specified with the PostClassifier object.
	 * Existing terms relationships are removed before classification.
	 *
	 * @param int  $post_id the post to classify & link
	 * @param bool $link_terms Whether to link the terms to the post.
	 * @return array|bool
	 */
	public function classify( int $post_id, bool $link_terms = true ) {
		/**
		 * Filter whether ClassifAI should classify a post.
		 *
		 * Default is true, return false to skip classifying a post.
		 *
		 * @since 1.2.0
		 * @hook classifai_should_classify_post
		 *
		 * @param {bool} $should_classify Whether the post should be classified. Default `true`, return `false` to skip
		 *                                classification for this post.
		 * @param {int}  $post_id         The ID of the post to be considered for classification.
		 *
		 * @return {bool} Whether the post should be classified.
		 */
		$classifai_should_classify_post = apply_filters( 'classifai_should_classify_post', true, $post_id );
		if ( ! $classifai_should_classify_post ) {
			return false;
		}

		$classifier = new PostClassifier();

		if ( $link_terms ) {
			if ( get_feature_enabled( 'category' ) ) {
				wp_delete_object_term_relationships( $post_id, get_feature_taxonomy( 'category' ) );
			}

			if ( get_feature_enabled( 'keyword' ) ) {
				wp_delete_object_term_relationships( $post_id, get_feature_taxonomy( 'keyword' ) );
			}

			if ( get_feature_enabled( 'concept' ) ) {
				wp_delete_object_term_relationships( $post_id, get_feature_taxonomy( 'concept' ) );
			}

			if ( get_feature_enabled( 'entity' ) ) {
				wp_delete_object_term_relationships( $post_id, get_feature_taxonomy( 'entity' ) );
			}
		}

		$output = $classifier->classify_and_link( $post_id, [], $link_terms );

		if ( is_wp_error( $output ) ) {
			update_post_meta(
				$post_id,
				'_classifai_error',
				wp_json_encode(
					[
						'code'    => $output->get_error_code(),
						'message' => $output->get_error_message(),
					]
				)
			);
		} else {
			// If there is no error, clear any existing error states.
			delete_post_meta( $post_id, '_classifai_error' );
		}

		return $output;
	}

	/**
	 * Returns the debug information for the provider settings.
	 *
	 * @return array
	 */
	public function get_debug_information(): array {
		$settings          = $this->feature_instance->get_settings();
		$provider_settings = $settings[ static::ID ];
		$debug_info        = [];

		if ( $this->feature_instance instanceof Classification ) {
			$debug_info[ __( 'Category (status)', 'classifai' ) ]    = Feature::get_debug_value_text( $provider_settings['category'], 1 );
			$debug_info[ __( 'Category (threshold)', 'classifai' ) ] = Feature::get_debug_value_text( $provider_settings['category_threshold'], 1 );
			$debug_info[ __( 'Category (taxonomy)', 'classifai' ) ]  = Feature::get_debug_value_text( $provider_settings['category_taxonomy'], 1 );

			$debug_info[ __( 'Keyword (status)', 'classifai' ) ]    = Feature::get_debug_value_text( $provider_settings['keyword'], 1 );
			$debug_info[ __( 'Keyword (threshold)', 'classifai' ) ] = Feature::get_debug_value_text( $provider_settings['keyword_threshold'], 1 );
			$debug_info[ __( 'Keyword (taxonomy)', 'classifai' ) ]  = Feature::get_debug_value_text( $provider_settings['keyword_taxonomy'], 1 );

			$debug_info[ __( 'Entity (status)', 'classifai' ) ]    = Feature::get_debug_value_text( $provider_settings['entity'], 1 );
			$debug_info[ __( 'Entity (threshold)', 'classifai' ) ] = Feature::get_debug_value_text( $provider_settings['entity_threshold'], 1 );
			$debug_info[ __( 'Entity (taxonomy)', 'classifai' ) ]  = Feature::get_debug_value_text( $provider_settings['entity_taxonomy'], 1 );

			$debug_info[ __( 'Concept (status)', 'classifai' ) ]    = Feature::get_debug_value_text( $provider_settings['concept'], 1 );
			$debug_info[ __( 'Concept (threshold)', 'classifai' ) ] = Feature::get_debug_value_text( $provider_settings['concept_threshold'], 1 );
			$debug_info[ __( 'Concept (taxonomy)', 'classifai' ) ]  = Feature::get_debug_value_text( $provider_settings['concept_taxonomy'], 1 );

			$debug_info[ __( 'Latest response', 'classifai' ) ] = $this->get_formatted_latest_response( get_transient( 'classifai_watson_nlu_latest_response' ) );
		}

		return apply_filters(
			'classifai_' . self::ID . '_debug_information',
			$debug_info,
			$settings,
			$this->feature_instance
		);
	}
}
