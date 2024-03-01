<?php
/**
 * Provides Text to Speech synthesis feature using Amazon Polly.
 *
 * @package Classifai\Providers\AWS
 * @since 3.1.0
 */

namespace Classifai\Providers\AWS;

use Classifai\Providers\Provider;
use Classifai\Normalizer;
use Classifai\Features\TextToSpeech;
use WP_Error;

class AmazonPolly extends Provider {

	const ID = 'aws_polly';

	/**
	 * Meta key to get/set the audio hash that helps to indicate if there is any need
	 * for the audio file to be regenerated or not.
	 *
	 * @var string
	 */
	const AUDIO_HASH_KEY = '_classifai_post_audio_hash';

	/**
	 * AmazonPolly Text to Speech constructor.
	 *
	 * @param \Classifai\Features\Feature $feature_instance The feature instance.
	 */
	public function __construct( $feature_instance = null ) {
		$this->feature_instance = $feature_instance;

		do_action( 'classifai_' . static::ID . '_init', $this );
	}

	/**
	 * Render the provider fields.
	 */
	public function render_provider_fields() {
		$settings = $this->feature_instance->get_settings( static::ID );

		add_settings_field(
			'access_key_id',
			esc_html__( 'AWS access key ID', 'classifai' ),
			[ $this->feature_instance, 'render_input' ],
			$this->feature_instance->get_option_name(),
			$this->feature_instance->get_option_name() . '_section',
			[
				'option_index'  => static::ID,
				'label_for'     => 'access_key_id',
				'input_type'    => 'text',
				'default_value' => $settings['access_key_id'],
				'class'         => 'large-text classifai-provider-field hidden provider-scope-' . static::ID, // Important to add this.
				'description'   => sprintf(
					wp_kses(
						/* translators: %1$s is replaced with the OpenAI sign up URL */
						__( 'Enter the AWS access key ID. Please follow the steps given <a title="AWS documentation" href="%1$s">here</a> to generate AWS credentials.', 'classifai' ),
						[
							'a' => [
								'href'  => [],
								'title' => [],
							],
						]
					),
					esc_url( 'https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html#Using_CreateAccessKey' )
				)
			]
		);

		add_settings_field(
			'secret_access_key',
			esc_html__( 'AWS secret access key', 'classifai' ),
			[ $this->feature_instance, 'render_input' ],
			$this->feature_instance->get_option_name(),
			$this->feature_instance->get_option_name() . '_section',
			[
				'option_index'  => static::ID,
				'label_for'     => 'secret_access_key',
				'input_type'    => 'password',
				'default_value' => $settings['secret_access_key'],
				'class'         => 'classifai-provider-field hidden provider-scope-' . static::ID, // Important to add this.
				'description'   => esc_html__( 'Enter the AWS secret access key.', 'classifai' ),
			]
		);

		add_settings_field(
			'aws_region',
			esc_html__( 'AWS Region', 'classifai' ),
			[ $this->feature_instance, 'render_input' ],
			$this->feature_instance->get_option_name(),
			$this->feature_instance->get_option_name() . '_section',
			[
				'option_index'  => static::ID,
				'label_for'     => 'aws_region',
				'input_type'    => 'text',
				'default_value' => $settings['aws_region'],
				'class'         => 'large-text classifai-provider-field hidden provider-scope-' . static::ID, // Important to add this.
				'description'   => wp_kses(
					__( 'Enter the AWS Region. eg: <code>us-east-1</code>', 'classifai' ),
					[
						'code' => [],
					]
				),
			]
		);

		$voices_options = $this->get_voices_select_options();
		if ( ! empty( $voices_options ) ) {
			add_settings_field(
				'voice',
				esc_html__( 'Voice', 'classifai' ),
				[ $this->feature_instance, 'render_select' ],
				$this->feature_instance->get_option_name(),
				$this->feature_instance->get_option_name() . '_section',
				[
					'option_index'  => static::ID,
					'label_for'     => 'voice',
					'options'       => $voices_options,
					'default_value' => $settings['voice'],
					'class'         => 'classifai-provider-field hidden provider-scope-' . static::ID, // Important to add this.
				]
			);
		}

		do_action( 'classifai_' . static::ID . '_render_provider_fields', $this );
	}

	/**
	 * Returns the default settings for this provider.
	 *
	 * @return array
	 */
	public function get_default_provider_settings(): array {
		$common_settings = [
			'access_key_id'     => '',
			'secret_access_key' => '',
			'aws_region'        => '',
			'authenticated'     => false,
			'voice_engine'      => 'standard',
			'voices'            => [],
			'voice'             => '',
		];

		switch ( $this->feature_instance::ID ) {
			case TextToSpeech::ID:
				return $common_settings;
		}

		return [];
	}

	/**
	 * Sanitization callback for settings.
	 *
	 * @param array $new_settings The settings being saved.
	 * @return array
	 */
	public function sanitize_settings( array $new_settings ): array {
		$settings               = $this->feature_instance->get_settings();
		$is_credentials_changed = false;

		$new_settings[ static::ID ]['authenticated'] = $settings[ static::ID ]['authenticated'];
		$new_settings[ static::ID ]['voices']        = $settings[ static::ID ]['voices'];

		if ( ! empty( $new_settings[ static::ID ]['access_key_id'] ) && ! empty( $new_settings[ static::ID ]['secret_access_key'] ) && ! empty( $new_settings[ static::ID ]['aws_region'] ) ) {
			$new_access_key_id     = sanitize_text_field( $new_settings[ static::ID ]['access_key_id'] );
			$new_secret_access_key = sanitize_text_field( $new_settings[ static::ID ]['secret_access_key'] );
			$new_aws_region        = sanitize_text_field( $new_settings[ static::ID ]['aws_region'] );

			if ( $new_access_key_id !== $settings[ static::ID ]['access_key_id'] || $new_secret_access_key !== $settings[ static::ID ]['secret_access_key'] || $new_aws_region !== $settings[ static::ID ]['aws_region'] ) {
				$is_credentials_changed = true;
			}

			if ( $is_credentials_changed ) {
				$new_settings[ static::ID ]['access_key_id']     = $new_access_key_id;
				$new_settings[ static::ID ]['secret_access_key'] = $new_secret_access_key;
				$new_settings[ static::ID ]['aws_region']        = $new_aws_region;
				$new_settings[ static::ID ]['voices']       = $this->connect_to_service(
					array(
						'access_key_id'     => $new_access_key_id,
						'secret_access_key' => $new_secret_access_key,
						'aws_region'        => $new_aws_region,
					)
				);

				if ( ! empty( $new_settings[ static::ID ]['voices'] ) ) {
					$new_settings[ static::ID ]['authenticated'] = true;
				} else {
					$new_settings[ static::ID ]['voices']        = [];
					$new_settings[ static::ID ]['authenticated'] = false;
				}
			}
		} else {
			$new_settings[ static::ID ]['access_key_id']     = $settings[ static::ID ]['access_key_id'];
			$new_settings[ static::ID ]['secret_access_key'] = $settings[ static::ID ]['secret_access_key'];
			$new_settings[ static::ID ]['aws_region']        = $settings[ static::ID ]['aws_region'];

			add_settings_error(
				$this->feature_instance->get_option_name(),
				'classifai-ams-polly-auth-empty',
				esc_html__( 'One or more credentials required to connect to the Amazon Polly service is empty.', 'classifai' ),
				'error'
			);
		}

		$new_settings[ static::ID ]['voice'] = sanitize_text_field( $new_settings[ static::ID ]['voice'] ?? $settings[ static::ID ]['voice'] );

		return $new_settings;
	}

	/**
	 * Connects to Azure's Text to Speech service.
	 *
	 * @param array $args Overridable args.
	 * @return array
	 */
	public function connect_to_service( array $args = array() ): array {
		$settings = $this->feature_instance->get_settings( static::ID );

		$default = array(
			'access_key_id'     => isset( $settings[ static::ID ]['access_key_id'] ) ? $settings[ static::ID ]['access_key_id'] : '',
			'secret_access_key' => isset( $settings[ static::ID ]['secret_access_key'] ) ? $settings[ static::ID ]['secret_access_key'] : '',
			'aws_region'        => isset( $settings[ static::ID ]['aws_region'] ) ? $settings[ static::ID ]['aws_region'] : 'us-east-1',
		);

		$default = wp_parse_args( $args, $default );

		// Return if credentials don't exist.
		if ( empty( $default['access_key_id'] ) || empty( $default['secret_access_key'] ) ) {
			return array();
		}

		try {
			$polly_client = $this->get_polly_client( $args );
			$polly_voices = $polly_client->describeVoices();
			return $polly_voices->get('Voices');
		} catch ( \Exception $e ) {
			add_settings_error(
				$this->feature_instance->get_option_name(),
				'aws-polly-auth-failed',
				esc_html__( 'Connection to Amazon Polly failed.', 'classifai' ),
				'error'
			);
			return array();
		}
	}

	/**
	 * Returns HTML select dropdown options for voices.
	 *
	 * @return array
	 */
	public function get_voices_select_options(): array {
		$settings = $this->feature_instance->get_settings( static::ID );
		$voices   = $settings['voices'];
		$options  = array();

		if ( false === $voices ) {
			return $options;
		}

		foreach ( $voices as $voice ) {
			if ( ! is_array( $voice ) || empty( $voice ) ) {
				continue;
			}

			$options[ $voice['Id'] ] = sprintf(
				'%1$s - %2$s (%3$s)',
				esc_html( $voice['LanguageName'] ),
				esc_html( $voice['Name'] ),
				esc_html( $voice['Gender'] )
			);
		}

		return $options;
	}

	/**
	 * Synthesizes speech from a post item.
	 *
	 * @param int $post_id Post ID.
	 * @return string|WP_Error
	 */
	public function synthesize_speech( int $post_id ) {
		if ( empty( $post_id ) ) {
			return new WP_Error(
				'aws_polly_post_id_missing',
				esc_html__( 'Post ID missing.', 'classifai' )
			);
		}

		// We skip the user cap check if running under WP-CLI.
		if ( ! current_user_can( 'edit_post', $post_id ) && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) {
			return new WP_Error(
				'aws_polly_user_not_authorized',
				esc_html__( 'Unauthorized user.', 'classifai' )
			);
		}

		$normalizer          = new Normalizer();
		$feature             = new TextToSpeech();
		$settings            = $feature->get_settings();
		$post                = get_post( $post_id );
		$post_content        = $normalizer->normalize_content( $post->post_content, $post->post_title, $post_id );
		$content_hash        = get_post_meta( $post_id, self::AUDIO_HASH_KEY, true );
		$saved_attachment_id = (int) get_post_meta( $post_id, $feature::AUDIO_ID_KEY, true );

		// Don't regenerate the audio file it it already exists and the content hasn't changed.
		if ( $saved_attachment_id ) {

			// Check if the audio file exists.
			$audio_attachment_url = wp_get_attachment_url( $saved_attachment_id );

			if ( $audio_attachment_url && ! empty( $content_hash ) && ( md5( $post_content ) === $content_hash ) ) {
				return $saved_attachment_id;
			}
		}

		$voice = $settings[ static::ID ]['voice'] ?? '';

		try {
			$polly_client = $this->get_polly_client();
			$result = $polly_client->synthesizeSpeech(
				array(
					'OutputFormat' => 'mp3',
					'Text'         => $post_content,
					'TextType'     => 'text',
					'VoiceId'      => $voice,
				)
			);

			update_post_meta( $post_id, self::AUDIO_HASH_KEY, md5( $post_content ) );
			$contents = $result['AudioStream']->getContents();
			return $contents;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'aws_polly_http_error',
				esc_html( $e->getMessage() )
			);
		}
	}

	/**
	 * Common entry point for all REST endpoints for this provider.
	 *
	 * @param int    $post_id       The post ID we're processing.
	 * @param string $route_to_call The name of the route we're going to be processing.
	 * @param array  $args          Optional arguments to pass to the route.
	 * @return array|string|WP_Error
	 */
	public function rest_endpoint_callback( $post_id, string $route_to_call = '', array $args = [] ) {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'post_id_required', esc_html__( 'A valid post ID is required.', 'classifai' ) );
		}

		$route_to_call = strtolower( $route_to_call );
		$return        = '';

		// Handle all of our routes.
		switch ( $route_to_call ) {
			case 'synthesize':
				$return = $this->synthesize_speech( $post_id, $args );
				break;
		}

		return $return;
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

		if ( $this->feature_instance instanceof TextToSpeech ) {
			$post_types = array_filter(
				$settings['post_types'],
				function ( $value ) {
					return '0' !== $value;
				}
			);

			$debug_info[ __( 'Allowed post types', 'classifai' ) ]       = implode( ', ', $post_types );
			$debug_info[ __( 'Voice', 'classifai' ) ]                    = $provider_settings['voice'];
			$debug_info[ __( 'Latest response - Voices', 'classifai' ) ] = $this->get_formatted_latest_response( $provider_settings['voices'] );
		}

		return apply_filters(
			'classifai_' . self::ID . '_debug_information',
			$debug_info,
			$settings,
			$this->feature_instance
		);
	}

	/**
	 * Returns aws polly client.
	 *
	 * @param array $aws_config AWS configuration array.
	 * @return \Aws\Polly\PollyClient|null
	 */
	public function get_polly_client( array $aws_config = array() ) {
		$settings = $this->feature_instance->get_settings( static::ID );

		$default = array(
			'access_key_id'     => isset( $settings[ static::ID ]['access_key_id'] ) ? $settings[ static::ID ]['access_key_id'] : '',
			'secret_access_key' => isset( $settings[ static::ID ]['secret_access_key'] ) ? $settings[ static::ID ]['secret_access_key'] : '',
			'aws_region'        => isset( $settings[ static::ID ]['aws_region'] ) ? $settings[ static::ID ]['aws_region'] : 'us-east-1',
		);

		$default = wp_parse_args( $aws_config, $default );

		// Return if credentials don't exist.
		if ( empty( $default['access_key_id'] ) || empty( $default['secret_access_key'] ) ) {
			return null;
		}

		// Set the AWS SDK configuration.
		$aws_sdk_config =  [
			'region'    => $default['aws_region'] ?? 'us-east-1',
			'version'   => 'latest',
			'ua_append' => ['request-source/classifai'],
			'credentials' => [
				'key'    => $default['access_key_id'],
				'secret' => $default['secret_access_key'],
			],
		];

		$sdk = new \Aws\Sdk($aws_sdk_config);
		return $sdk->createPolly();
	}
}
