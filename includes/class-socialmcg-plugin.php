<?php
/**
 * Main plugin class.
 *
 * @package SocialMediaCardGenerator
 */

namespace Peal333\SocialMediaCardGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates settings, admin UI, font management and card generation.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Plugin defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'socialmcg_template_image_id'         => 0,
			'socialmcg_title_font'                => SOCIALMCG_DEFAULT_FONT,
			'socialmcg_title_font_size'           => 82,
			'socialmcg_title_color'               => '#f0f8ff',
			'socialmcg_title_y_position'          => 50,
			'socialmcg_title_alignment'           => 'center',
			'socialmcg_description_font'          => SOCIALMCG_DEFAULT_FONT,
			'socialmcg_description_font_size'     => 42,
			'socialmcg_description_color'         => '#e0e0e0',
			'socialmcg_description_y_position'    => 88,
			'socialmcg_description_alignment'     => 'center',
			'socialmcg_shadow_enabled'            => 0,
			'socialmcg_shadow_color'              => '#000000',
			'socialmcg_shadow_opacity'            => 50,
			'socialmcg_shadow_offset_x'           => 2,
			'socialmcg_shadow_offset_y'           => 2,
			'socialmcg_output_format'             => 'jpeg',
			'socialmcg_jpeg_quality'              => 70,
			'socialmcg_plugin_version'            => SOCIALMCG_VERSION,
		);
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		foreach ( self::defaults() as $name => $value ) {
			add_option( $name, $value );
		}

		update_option( 'socialmcg_plugin_version', SOCIALMCG_VERSION );
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'socialmcg_requirement_check' );
		delete_transient( 'socialmcg_font_check' );
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ), 5 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'check_requirements' ) );

		add_action( 'wp_ajax_socialmcg_generate_image', array( $this, 'generate_image_callback' ) );
		add_action( 'wp_ajax_socialmcg_save_aioseo_preference', array( $this, 'save_aioseo_preference_callback' ) );
		add_action( 'wp_ajax_socialmcg_upload_font', array( $this, 'upload_font_callback' ) );
		add_action( 'wp_ajax_socialmcg_delete_font', array( $this, 'delete_font_callback' ) );
	}

	/**
	 * Add any new defaults after an upgrade without overwriting existing settings.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$stored_version = get_option( 'socialmcg_plugin_version', '0.0.0' );

		if ( version_compare( $stored_version, SOCIALMCG_VERSION, '>=' ) ) {
			return;
		}

		foreach ( self::defaults() as $name => $value ) {
			if ( 'socialmcg_plugin_version' === $name ) {
				continue;
			}
			add_option( $name, $value );
		}

		update_option( 'socialmcg_plugin_version', SOCIALMCG_VERSION );
	}

	/**
	 * Fetch a plugin option with its canonical fallback.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private function get_option_value( $name ) {
		$defaults = self::defaults();
		$default  = isset( $defaults[ $name ] ) ? $defaults[ $name ] : false;

		return get_option( $name, $default );
	}

	/**
	 * Register settings with sanitizers.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'socialmcg_settings_group',
			'socialmcg_template_image_id',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_template_image_id' ),
				'default'           => 0,
			)
		);

		register_setting( 'socialmcg_settings_group', 'socialmcg_title_font', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_font_key' ), 'default' => SOCIALMCG_DEFAULT_FONT ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_title_font_size', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_font_size' ), 'default' => 82 ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_title_color', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_title_color' ), 'default' => '#f0f8ff' ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_title_y_position', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_position_percentage' ), 'default' => 50 ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_title_alignment', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_alignment' ), 'default' => 'center' ) );

		register_setting( 'socialmcg_settings_group', 'socialmcg_description_font', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_font_key' ), 'default' => SOCIALMCG_DEFAULT_FONT ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_description_font_size', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_font_size' ), 'default' => 42 ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_description_color', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_description_color' ), 'default' => '#e0e0e0' ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_description_y_position', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_position_percentage' ), 'default' => 88 ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_description_alignment', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_alignment' ), 'default' => 'center' ) );

		register_setting( 'socialmcg_settings_group', 'socialmcg_shadow_enabled', array( 'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => 0 ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_shadow_color', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_shadow_color' ), 'default' => '#000000' ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_shadow_opacity', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_shadow_opacity' ), 'default' => 50 ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_shadow_offset_x', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_shadow_offset' ), 'default' => 2 ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_shadow_offset_y', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_shadow_offset' ), 'default' => 2 ) );

		register_setting( 'socialmcg_settings_group', 'socialmcg_output_format', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_output_format' ), 'default' => 'jpeg' ) );
		register_setting( 'socialmcg_settings_group', 'socialmcg_jpeg_quality', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_jpeg_quality' ), 'default' => 70 ) );
	}

	/**
	 * Sanitize template attachment ID.
	 *
	 * @param mixed $input Submitted value.
	 * @return int
	 */
	public function sanitize_template_image_id( $input ) {
		$attachment_id = absint( $input );

		if ( 0 === $attachment_id ) {
			return 0;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			add_settings_error( 'socialmcg_messages', 'socialmcg_invalid_template', __( 'The selected template must be an image from the Media Library.', 'social-media-card-generator' ), 'error' );
			return absint( $this->get_option_value( 'socialmcg_template_image_id' ) );
		}

		return $attachment_id;
	}

	/**
	 * Sanitize a font selection.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public function sanitize_font_key( $input ) {
		$input = sanitize_text_field( wp_unslash( (string) $input ) );
		$fonts = $this->get_font_choices();

		return isset( $fonts[ $input ] ) ? $input : SOCIALMCG_DEFAULT_FONT;
	}

	/**
	 * Sanitize a font size.
	 *
	 * @param mixed $input Submitted value.
	 * @return int
	 */
	public function sanitize_font_size( $input ) {
		if ( ! is_numeric( $input ) ) {
			return 10;
		}

		return max( 10, min( 300, absint( $input ) ) );
	}

	/**
	 * Sanitize a percentage position.
	 *
	 * @param mixed $input Submitted value.
	 * @return int
	 */
	public function sanitize_position_percentage( $input ) {
		if ( ! is_numeric( $input ) ) {
			return 50;
		}

		return max( 0, min( 100, absint( $input ) ) );
	}

	/**
	 * Sanitize horizontal text alignment.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public function sanitize_alignment( $input ) {
		$allowed = array( 'left', 'center', 'right' );
		$input   = sanitize_key( (string) $input );

		return in_array( $input, $allowed, true ) ? $input : 'center';
	}

	/**
	 * Sanitize the title color.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public function sanitize_title_color( $input ) {
		return $this->sanitize_color( $input, '#f0f8ff' );
	}

	/**
	 * Sanitize the description color.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public function sanitize_description_color( $input ) {
		return $this->sanitize_color( $input, '#e0e0e0' );
	}

	/**
	 * Sanitize the shadow color.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public function sanitize_shadow_color( $input ) {
		return $this->sanitize_color( $input, '#000000' );
	}

	/**
	 * Sanitize a hex color.
	 *
	 * @param mixed  $input   Submitted value.
	 * @param string $default Default color.
	 * @return string
	 */
	private function sanitize_color( $input, $default ) {
		$color = sanitize_hex_color( (string) $input );
		return $color ? $color : $default;
	}

	/**
	 * Sanitize checkbox input.
	 *
	 * @param mixed $input Submitted value.
	 * @return int
	 */
	public function sanitize_checkbox( $input ) {
		return empty( $input ) ? 0 : 1;
	}

	/**
	 * Sanitize shadow opacity.
	 *
	 * @param mixed $input Submitted value.
	 * @return int
	 */
	public function sanitize_shadow_opacity( $input ) {
		if ( ! is_numeric( $input ) ) {
			return 50;
		}

		return max( 0, min( 100, absint( $input ) ) );
	}

	/**
	 * Sanitize shadow offsets.
	 *
	 * @param mixed $input Submitted value.
	 * @return int
	 */
	public function sanitize_shadow_offset( $input ) {
		if ( ! is_numeric( $input ) ) {
			return 0;
		}

		$value = (int) $input;
		return max( -50, min( 50, $value ) );
	}

	/**
	 * Sanitize image output format.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public function sanitize_output_format( $input ) {
		$input = sanitize_key( (string) $input );
		return in_array( $input, array( 'jpeg', 'png' ), true ) ? $input : 'jpeg';
	}

	/**
	 * Sanitize JPEG quality.
	 *
	 * @param mixed $input Submitted value.
	 * @return int
	 */
	public function sanitize_jpeg_quality( $input ) {
		if ( ! is_numeric( $input ) ) {
			return 70;
		}

		return max( 1, min( 100, absint( $input ) ) );
	}

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Social Media Card Generator', 'social-media-card-generator' ),
			__( 'Social Media Card Generator', 'social-media-card-generator' ),
			'manage_options',
			'social-media-card-generator',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$template_id      = absint( $this->get_option_value( 'socialmcg_template_image_id' ) );
		$font_choices     = $this->get_font_choices();
		$title_font       = (string) $this->get_option_value( 'socialmcg_title_font' );
		$description_font = (string) $this->get_option_value( 'socialmcg_description_font' );
		$shadow_enabled   = (bool) $this->get_option_value( 'socialmcg_shadow_enabled' );
		$output_format    = (string) $this->get_option_value( 'socialmcg_output_format' );
		?>
		<div class="wrap socialmcg-settings-wrap">
			<div class="socialmcg-settings-header">
				<div>
					<h1><?php esc_html_e( 'Social Media Card Generator', 'social-media-card-generator' ); ?></h1>
					<p><?php esc_html_e( 'Create consistent, branded social cards directly from the WordPress editor.', 'social-media-card-generator' ); ?></p>
				</div>
				<span class="socialmcg-version-badge"><?php echo esc_html( 'v' . SOCIALMCG_VERSION ); ?></span>
			</div>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" class="socialmcg-settings-form">
				<?php settings_fields( 'socialmcg_settings_group' ); ?>

				<div class="socialmcg-settings-grid">
					<div class="socialmcg-settings-main">
						<section class="socialmcg-card">
							<div class="socialmcg-card-heading">
								<div>
									<h2><?php esc_html_e( 'Template', 'social-media-card-generator' ); ?></h2>
									<p><?php esc_html_e( 'Choose the background image used for every generated card.', 'social-media-card-generator' ); ?></p>
								</div>
							</div>
							<div class="socialmcg-template-layout">
								<div class="socialmcg-template-controls">
									<input type="hidden" id="socialmcg_template_image_id" name="socialmcg_template_image_id" value="<?php echo esc_attr( $template_id ); ?>">
									<button type="button" class="button button-secondary" id="socialmcg_upload_image_button"><?php esc_html_e( 'Choose Template Image', 'social-media-card-generator' ); ?></button>
									<button type="button" class="button button-link-delete<?php echo $template_id ? '' : ' hidden'; ?>" id="socialmcg_remove_image_button"><?php esc_html_e( 'Remove', 'social-media-card-generator' ); ?></button>
									<p class="description"><?php esc_html_e( 'Recommended size: 1200 × 630 px. Maximum source file size: 5 MB.', 'social-media-card-generator' ); ?></p>
								</div>
								<div id="socialmcg_template_image_preview" class="socialmcg-template-preview<?php echo $template_id ? '' : ' is-empty'; ?>">
									<?php
									if ( $template_id ) {
										echo wp_get_attachment_image( $template_id, 'large', false, array( 'alt' => esc_attr__( 'Selected social card template', 'social-media-card-generator' ) ) );
									} else {
										echo '<span>' . esc_html__( 'No template selected yet.', 'social-media-card-generator' ) . '</span>';
									}
									?>
								</div>
							</div>
						</section>

						<section class="socialmcg-card">
							<div class="socialmcg-card-heading">
								<div>
									<h2><?php esc_html_e( 'Title Typography', 'social-media-card-generator' ); ?></h2>
									<p><?php esc_html_e( 'Controls for the main title text.', 'social-media-card-generator' ); ?></p>
								</div>
							</div>
							<div class="socialmcg-field-grid">
								<?php $this->render_font_select_field( 'socialmcg_title_font', __( 'Font', 'social-media-card-generator' ), $title_font, $font_choices ); ?>
								<?php $this->render_number_field( 'socialmcg_title_font_size', __( 'Font size', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_title_font_size' ), 10, 300, 1, 'px' ); ?>
								<?php $this->render_color_field( 'socialmcg_title_color', __( 'Text color', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_title_color' ) ); ?>
								<?php $this->render_number_field( 'socialmcg_title_y_position', __( 'Vertical position', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_title_y_position' ), 0, 100, 1, '%' ); ?>
								<?php $this->render_alignment_field( 'socialmcg_title_alignment', __( 'Alignment', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_title_alignment' ) ); ?>
							</div>
						</section>

						<section class="socialmcg-card">
							<div class="socialmcg-card-heading">
								<div>
									<h2><?php esc_html_e( 'Description Typography', 'social-media-card-generator' ); ?></h2>
									<p><?php esc_html_e( 'Controls for the optional supporting text.', 'social-media-card-generator' ); ?></p>
								</div>
							</div>
							<div class="socialmcg-field-grid">
								<?php $this->render_font_select_field( 'socialmcg_description_font', __( 'Font', 'social-media-card-generator' ), $description_font, $font_choices ); ?>
								<?php $this->render_number_field( 'socialmcg_description_font_size', __( 'Font size', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_description_font_size' ), 10, 300, 1, 'px' ); ?>
								<?php $this->render_color_field( 'socialmcg_description_color', __( 'Text color', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_description_color' ) ); ?>
								<?php $this->render_number_field( 'socialmcg_description_y_position', __( 'Vertical position', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_description_y_position' ), 0, 100, 1, '%' ); ?>
								<?php $this->render_alignment_field( 'socialmcg_description_alignment', __( 'Alignment', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_description_alignment' ) ); ?>
							</div>
						</section>

						<section class="socialmcg-card">
							<div class="socialmcg-card-heading socialmcg-card-heading-inline">
								<div>
									<h2><?php esc_html_e( 'Text Shadow', 'social-media-card-generator' ); ?></h2>
									<p><?php esc_html_e( 'Disabled by default to match the previous hardcoded card style.', 'social-media-card-generator' ); ?></p>
								</div>
								<label class="socialmcg-switch">
									<input type="hidden" name="socialmcg_shadow_enabled" value="0">
									<input type="checkbox" id="socialmcg_shadow_enabled" name="socialmcg_shadow_enabled" value="1" <?php checked( $shadow_enabled ); ?>>
									<span class="socialmcg-switch-ui" aria-hidden="true"></span>
									<span><?php esc_html_e( 'Enable', 'social-media-card-generator' ); ?></span>
								</label>
							</div>
							<div id="socialmcg_shadow_controls" class="socialmcg-field-grid<?php echo $shadow_enabled ? '' : ' is-disabled'; ?>">
								<?php $this->render_color_field( 'socialmcg_shadow_color', __( 'Shadow color', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_shadow_color' ) ); ?>
								<?php $this->render_number_field( 'socialmcg_shadow_opacity', __( 'Opacity', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_shadow_opacity' ), 0, 100, 1, '%' ); ?>
								<?php $this->render_number_field( 'socialmcg_shadow_offset_x', __( 'Horizontal offset', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_shadow_offset_x' ), -50, 50, 1, 'px' ); ?>
								<?php $this->render_number_field( 'socialmcg_shadow_offset_y', __( 'Vertical offset', 'social-media-card-generator' ), $this->get_option_value( 'socialmcg_shadow_offset_y' ), -50, 50, 1, 'px' ); ?>
							</div>
						</section>

						<section class="socialmcg-card">
							<div class="socialmcg-card-heading">
								<div>
									<h2><?php esc_html_e( 'Output', 'social-media-card-generator' ); ?></h2>
									<p><?php esc_html_e( 'Choose the format used when the generated card is saved.', 'social-media-card-generator' ); ?></p>
								</div>
							</div>
							<div class="socialmcg-field-grid">
								<div class="socialmcg-field">
									<label for="socialmcg_output_format"><?php esc_html_e( 'Image format', 'social-media-card-generator' ); ?></label>
									<select id="socialmcg_output_format" name="socialmcg_output_format">
										<option value="jpeg" <?php selected( $output_format, 'jpeg' ); ?>><?php esc_html_e( 'JPEG', 'social-media-card-generator' ); ?></option>
										<option value="png" <?php selected( $output_format, 'png' ); ?>><?php esc_html_e( 'PNG', 'social-media-card-generator' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'JPEG is smaller; PNG preserves lossless image quality.', 'social-media-card-generator' ); ?></p>
								</div>
								<div class="socialmcg-field" id="socialmcg_jpeg_quality_field">
									<label for="socialmcg_jpeg_quality"><?php esc_html_e( 'JPEG quality', 'social-media-card-generator' ); ?></label>
									<div class="socialmcg-input-with-unit">
										<input type="number" id="socialmcg_jpeg_quality" name="socialmcg_jpeg_quality" min="1" max="100" step="1" value="<?php echo esc_attr( $this->get_option_value( 'socialmcg_jpeg_quality' ) ); ?>">
										<span><?php esc_html_e( '%', 'social-media-card-generator' ); ?></span>
									</div>
								</div>
							</div>
						</section>
					</div>

					<aside class="socialmcg-settings-sidebar">
						<section class="socialmcg-card socialmcg-font-library-card">
							<div class="socialmcg-card-heading">
								<div>
									<h2><?php esc_html_e( 'Font Library', 'social-media-card-generator' ); ?></h2>
									<p><?php esc_html_e( 'Open Sans is bundled. You can also upload TrueType (.ttf) fonts.', 'social-media-card-generator' ); ?></p>
								</div>
							</div>
							<div class="socialmcg-font-upload">
								<label class="screen-reader-text" for="socialmcg_font_file"><?php esc_html_e( 'Choose a TrueType font file', 'social-media-card-generator' ); ?></label>
								<input type="file" id="socialmcg_font_file" accept=".ttf,font/ttf">
								<button type="button" class="button button-secondary" id="socialmcg_upload_font_button"><?php esc_html_e( 'Upload Font', 'social-media-card-generator' ); ?></button>
							</div>
							<div id="socialmcg_font_notice" class="socialmcg-inline-notice" aria-live="polite"></div>
							<div class="socialmcg-custom-fonts" id="socialmcg_custom_fonts">
								<?php $this->render_custom_font_list(); ?>
							</div>
							<p class="description socialmcg-font-license-note"><?php esc_html_e( 'Only upload fonts you have permission to use. Custom fonts are stored in the WordPress uploads directory, not inside the plugin.', 'social-media-card-generator' ); ?></p>
						</section>

						<section class="socialmcg-card socialmcg-help-card">
							<h2><?php esc_html_e( 'Recommended Setup', 'social-media-card-generator' ); ?></h2>
							<ul>
								<li><?php esc_html_e( 'Use a 1200 × 630 px template.', 'social-media-card-generator' ); ?></li>
								<li><?php esc_html_e( 'Keep important artwork away from the outer edges.', 'social-media-card-generator' ); ?></li>
								<li><?php esc_html_e( 'Preview long titles before publishing.', 'social-media-card-generator' ); ?></li>
							</ul>
						</section>
					</aside>
				</div>

				<div class="socialmcg-settings-actions">
					<?php submit_button( __( 'Save Settings', 'social-media-card-generator' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a font selector.
	 *
	 * @param string $name    Field name.
	 * @param string $label   Field label.
	 * @param string $current Current value.
	 * @param array  $fonts   Font choices.
	 * @return void
	 */
	private function render_font_select_field( $name, $label, $current, $fonts ) {
		$bundled = array();
		$custom  = array();

		foreach ( $fonts as $key => $font ) {
			if ( 0 === strpos( $key, 'custom:' ) ) {
				$custom[ $key ] = $font;
			} else {
				$bundled[ $key ] = $font;
			}
		}
		?>
		<div class="socialmcg-field">
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="socialmcg-font-select-row">
				<select class="socialmcg-font-select" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<optgroup label="<?php esc_attr_e( 'Bundled', 'social-media-card-generator' ); ?>">
						<?php foreach ( $bundled as $key => $font ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $font['label'] ); ?></option>
						<?php endforeach; ?>
					</optgroup>
					<optgroup class="socialmcg-custom-font-options" label="<?php esc_attr_e( 'Custom', 'social-media-card-generator' ); ?>">
						<?php if ( empty( $custom ) ) : ?>
							<option value="" disabled><?php esc_html_e( 'No custom fonts uploaded', 'social-media-card-generator' ); ?></option>
						<?php else : ?>
							<?php foreach ( $custom as $key => $font ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $font['label'] ); ?></option>
							<?php endforeach; ?>
						<?php endif; ?>
					</optgroup>
				</select>
				<button type="button" class="button button-small socialmcg-reset-font" data-target="<?php echo esc_attr( $name ); ?>"<?php disabled( SOCIALMCG_DEFAULT_FONT, $current ); ?>><?php esc_html_e( 'Use Open Sans', 'social-media-card-generator' ); ?></button>
			</div>
			<p class="description"><?php esc_html_e( 'Choose Open Sans at any time to return to the bundled default font.', 'social-media-card-generator' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render a numeric field.
	 *
	 * @param string $name  Input name.
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @param int    $step  Step.
	 * @param string $unit  Display unit.
	 * @return void
	 */
	private function render_number_field( $name, $label, $value, $min, $max, $step, $unit ) {
		?>
		<div class="socialmcg-field">
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="socialmcg-input-with-unit">
				<input type="number" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>">
				<span><?php echo esc_html( $unit ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a WordPress color picker field.
	 *
	 * @param string $name  Input name.
	 * @param string $label Label.
	 * @param string $value Color.
	 * @return void
	 */
	private function render_color_field( $name, $label, $value ) {
		?>
		<div class="socialmcg-field">
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="text" class="socialmcg-color-picker" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" data-default-color="<?php echo esc_attr( $value ); ?>">
		</div>
		<?php
	}

	/**
	 * Render an alignment selector.
	 *
	 * @param string $name    Input name.
	 * @param string $label   Label.
	 * @param string $current Current value.
	 * @return void
	 */
	private function render_alignment_field( $name, $label, $current ) {
		?>
		<div class="socialmcg-field">
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
				<option value="left" <?php selected( $current, 'left' ); ?>><?php esc_html_e( 'Left', 'social-media-card-generator' ); ?></option>
				<option value="center" <?php selected( $current, 'center' ); ?>><?php esc_html_e( 'Center', 'social-media-card-generator' ); ?></option>
				<option value="right" <?php selected( $current, 'right' ); ?>><?php esc_html_e( 'Right', 'social-media-card-generator' ); ?></option>
			</select>
		</div>
		<?php
	}

	/**
	 * Add the post editor meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		add_meta_box(
			'socialmcg_meta_box',
			__( 'Social Media Card Generator', 'social-media-card-generator' ),
			array( $this, 'render_meta_box' ),
			'post',
			'side',
			'high'
		);
	}

	/**
	 * Render the post editor meta box.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$title         = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$aioseo_status     = $this->get_aioseo_status();
		$aioseo_preference = (bool) get_user_meta( get_current_user_id(), 'socialmcg_aioseo_social_images', true );
		?>
		<div class="socialmcg-meta-box-wrapper">
			<div class="socialmcg-meta-intro">
				<p><?php esc_html_e( 'Generate a branded social image and save it directly to the Media Library.', 'social-media-card-generator' ); ?></p>
			</div>

			<div class="socialmcg-meta-field">
				<label for="socialmcg_title"><?php esc_html_e( 'Title', 'social-media-card-generator' ); ?></label>
				<textarea id="socialmcg_title" rows="3" required><?php echo esc_textarea( $title ); ?></textarea>
			</div>

			<div class="socialmcg-meta-field">
				<div class="socialmcg-label-row">
					<label for="socialmcg_description"><?php esc_html_e( 'Description', 'social-media-card-generator' ); ?></label>
					<span><?php esc_html_e( 'Optional', 'social-media-card-generator' ); ?></span>
				</div>
				<textarea id="socialmcg_description" rows="3" maxlength="200"></textarea>
				<div class="socialmcg-char-count"><span id="socialmcg_description_count">0</span>/200</div>
			</div>

			<?php if ( 'active' === $aioseo_status['state'] ) : ?>
				<label class="socialmcg-aioseo-option" for="socialmcg_set_aioseo">
					<input type="checkbox" id="socialmcg_set_aioseo" value="1" <?php checked( $aioseo_preference ); ?>>
					<span>
						<strong><?php esc_html_e( 'Use this as the AIOSEO Social Image', 'social-media-card-generator' ); ?></strong>
						<small><?php esc_html_e( 'Sets both Facebook and Twitter image sources to Custom Image. This preference is remembered for your account.', 'social-media-card-generator' ); ?></small>
					</span>
				</label>
			<?php elseif ( 'inactive' === $aioseo_status['state'] ) : ?>
				<div class="socialmcg-integration-note">
					<?php esc_html_e( 'AIOSEO is installed but inactive. Activate it to enable automatic social image updates.', 'social-media-card-generator' ); ?>
				</div>
			<?php elseif ( 'unsupported' === $aioseo_status['state'] ) : ?>
				<div class="socialmcg-integration-note">
					<?php esc_html_e( 'AIOSEO is active, but this version does not expose the post model needed for automatic social image updates. Please update AIOSEO.', 'social-media-card-generator' ); ?>
				</div>
			<?php endif; ?>

			<button type="button" id="socialmcg_generate_button" class="button button-primary socialmcg-generate-button">
				<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
				<?php esc_html_e( 'Generate Card', 'social-media-card-generator' ); ?>
			</button>

			<div id="socialmcg_loader" class="socialmcg-loader" hidden>
				<span class="spinner is-active"></span>
				<span><?php esc_html_e( 'Generating your card…', 'social-media-card-generator' ); ?></span>
			</div>

			<div id="socialmcg_error" class="notice notice-error inline socialmcg-result-notice" hidden><p></p></div>
			<div id="socialmcg_success" class="notice notice-success inline socialmcg-result-notice" hidden><p></p></div>
			<div id="socialmcg_integration_result" class="socialmcg-integration-result" hidden></div>

			<div id="socialmcg_image_preview" class="socialmcg-image-preview">
				<p class="description socialmcg-preview-placeholder"><?php esc_html_e( 'Your generated card preview will appear here.', 'social-media-card-generator' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Check system requirements and display admin notices.
	 *
	 * @return void
	 */
	public function check_requirements() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$missing = array();

		if ( ! extension_loaded( 'gd' ) ) {
			$missing[] = __( 'The PHP GD extension is required for image generation.', 'social-media-card-generator' );
		} elseif ( ! function_exists( 'imagettftext' ) || ! function_exists( 'imagettfbbox' ) ) {
			$missing[] = __( 'GD is installed, but FreeType text support is unavailable. TrueType rendering requires GD with FreeType support.', 'social-media-card-generator' );
		}

		$bundled_font = SOCIALMCG_PLUGIN_DIR . 'fonts/OpenSans-Regular.ttf';
		if ( ! is_readable( $bundled_font ) ) {
			$missing[] = __( 'The bundled Open Sans font is missing or unreadable. Reinstall the plugin package.', 'social-media-card-generator' );
		}

		foreach ( $missing as $message ) {
			?>
			<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Social Media Card Generator:', 'social-media-card-generator' ); ?></strong> <?php echo esc_html( $message ); ?></p></div>
			<?php
		}
	}

	/**
	 * AJAX: generate a social card.
	 *
	 * @return void
	 */
	public function generate_image_callback() {
		check_ajax_referer( 'socialmcg_generate_image_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to generate media for this post.', 'social-media-card-generator' ) ), 403 );
		}

		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'imagettftext' ) || ! function_exists( 'imagettfbbox' ) ) {
			wp_send_json_error( array( 'message' => __( 'GD with FreeType support is required to generate cards.', 'social-media-card-generator' ) ), 500 );
		}

		$title       = isset( $_POST['title'] ) ? sanitize_textarea_field( wp_unslash( $_POST['title'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$set_aioseo  = isset( $_POST['set_aioseo'] ) && 1 === absint( wp_unslash( $_POST['set_aioseo'] ) );
		update_user_meta( get_current_user_id(), 'socialmcg_aioseo_social_images', $set_aioseo ? 1 : 0 );

		if ( '' === trim( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'A title is required.', 'social-media-card-generator' ) ), 400 );
		}

		$template_id = absint( $this->get_option_value( 'socialmcg_template_image_id' ) );
		if ( ! $template_id ) {
			wp_send_json_error( array( 'message' => __( 'No template image is selected. Choose one in Settings > Social Media Card Generator.', 'social-media-card-generator' ) ), 400 );
		}

		$template_path = get_attached_file( $template_id );
		if ( ! $template_path || ! is_readable( $template_path ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected template image file could not be found.', 'social-media-card-generator' ) ), 400 );
		}

		if ( filesize( $template_path ) > SOCIALMCG_MAX_IMAGE_SIZE ) {
			wp_send_json_error( array( 'message' => __( 'The template image is larger than the 5 MB limit.', 'social-media-card-generator' ) ), 400 );
		}

		wp_raise_memory_limit( 'image' );

		$result = $this->create_social_card_image( $template_path, $title, $description, $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		if ( $set_aioseo ) {
			$aioseo_result = $this->update_aioseo_social_images( $post_id, $result['attachment_id'], $result['image_url'] );
			if ( is_wp_error( $aioseo_result ) ) {
				$result['aioseo'] = array(
					'success' => false,
					'message' => $aioseo_result->get_error_message(),
				);
			} else {
				$result['aioseo'] = array(
					'success' => true,
					'message' => __( 'AIOSEO Facebook and Twitter images updated.', 'social-media-card-generator' ),
				);
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * Create the card image.
	 *
	 * @param string $template_path Template filesystem path.
	 * @param string $title         Title text.
	 * @param string $description   Description text.
	 * @param int    $post_id       Parent post ID.
	 * @return array|\WP_Error
	 */
	private function create_social_card_image( $template_path, $title, $description, $post_id ) {
		$image_info = @getimagesize( $template_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid source images are handled below.
		if ( ! $image_info || empty( $image_info['mime'] ) ) {
			return new \WP_Error( 'socialmcg_invalid_image', __( 'The template image is invalid or corrupted.', 'social-media-card-generator' ) );
		}

		$image = null;
		switch ( $image_info['mime'] ) {
			case 'image/jpeg':
				$image = @imagecreatefromjpeg( $template_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			case 'image/png':
				$image = @imagecreatefrompng( $template_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			case 'image/gif':
				$image = @imagecreatefromgif( $template_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			case 'image/webp':
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					$image = @imagecreatefromwebp( $template_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				break;
		}

		if ( ! $image ) {
			return new \WP_Error( 'socialmcg_image_creation_failed', __( 'The template image format is not supported by this server.', 'social-media-card-generator' ) );
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );
		if ( $width < 100 || $height < 100 ) {
			imagedestroy( $image );
			return new \WP_Error( 'socialmcg_image_too_small', __( 'The template image is too small. Minimum dimensions are 100 × 100 px.', 'social-media-card-generator' ) );
		}

		$title_font_path       = $this->resolve_font_path( (string) $this->get_option_value( 'socialmcg_title_font' ) );
		$description_font_path = $this->resolve_font_path( (string) $this->get_option_value( 'socialmcg_description_font' ) );

		if ( ! $title_font_path || ! $description_font_path ) {
			imagedestroy( $image );
			return new \WP_Error( 'socialmcg_font_missing', __( 'A selected font is unavailable. Choose an available font in the plugin settings.', 'social-media-card-generator' ) );
		}

		$title_color       = $this->allocate_hex_color( $image, (string) $this->get_option_value( 'socialmcg_title_color' ), 1 );
		$description_color = $this->allocate_hex_color( $image, (string) $this->get_option_value( 'socialmcg_description_color' ), 1 );

		$shadow = array(
			'enabled'  => (bool) $this->get_option_value( 'socialmcg_shadow_enabled' ),
			'color'    => null,
			'offset_x' => (int) $this->get_option_value( 'socialmcg_shadow_offset_x' ),
			'offset_y' => (int) $this->get_option_value( 'socialmcg_shadow_offset_y' ),
		);

		if ( $shadow['enabled'] ) {
			$opacity         = max( 0, min( 100, (int) $this->get_option_value( 'socialmcg_shadow_opacity' ) ) );
			$gd_alpha        = 127 - (int) round( 127 * ( $opacity / 100 ) );
			$shadow['color'] = $this->allocate_hex_color( $image, (string) $this->get_option_value( 'socialmcg_shadow_color' ), $gd_alpha );
		}

		$title_result = $this->add_text_to_image(
			$image,
			$title,
			$title_font_path,
			(int) $this->get_option_value( 'socialmcg_title_font_size' ),
			$title_color,
			$shadow,
			$width,
			$height,
			(int) $this->get_option_value( 'socialmcg_title_y_position' ),
			(string) $this->get_option_value( 'socialmcg_title_alignment' )
		);

		if ( is_wp_error( $title_result ) ) {
			imagedestroy( $image );
			return $title_result;
		}

		if ( '' !== trim( $description ) ) {
			$description_result = $this->add_text_to_image(
				$image,
				$description,
				$description_font_path,
				(int) $this->get_option_value( 'socialmcg_description_font_size' ),
				$description_color,
				$shadow,
				$width,
				$height,
				(int) $this->get_option_value( 'socialmcg_description_y_position' ),
				(string) $this->get_option_value( 'socialmcg_description_alignment' )
			);

			if ( is_wp_error( $description_result ) ) {
				imagedestroy( $image );
				return $description_result;
			}
		}

		$save_result = $this->save_generated_image( $image, $title, $post_id );
		imagedestroy( $image );

		return $save_result;
	}

	/**
	 * Draw wrapped text on the image.
	 *
	 * @param resource|\GdImage $image              GD image.
	 * @param string            $text               Text.
	 * @param string            $font_path          Font path.
	 * @param int               $font_size          Font size.
	 * @param int               $text_color         GD color index.
	 * @param array             $shadow             Shadow configuration.
	 * @param int               $width              Image width.
	 * @param int               $height             Image height.
	 * @param int               $y_position_percent Vertical block center.
	 * @param string            $alignment          left|center|right.
	 * @return true|\WP_Error
	 */
	private function add_text_to_image( $image, $text, $font_path, $font_size, $text_color, $shadow, $width, $height, $y_position_percent, $alignment ) {
		$max_width = $width * 0.9;
		$lines     = $this->wrap_text( $text, $font_path, $font_size, $max_width );

		if ( empty( $lines ) ) {
			return new \WP_Error( 'socialmcg_text_wrap_failed', __( 'The text could not be rendered with the selected font.', 'social-media-card-generator' ) );
		}

		$line_bbox = imagettfbbox( $font_size, 0, $font_path, 'Sg' );
		if ( false === $line_bbox ) {
			return new \WP_Error( 'socialmcg_font_error', __( 'The selected font could not be measured.', 'social-media-card-generator' ) );
		}

		$line_height       = max( 1, $line_bbox[1] - $line_bbox[7] );
		$total_text_height = count( $lines ) * $line_height;
		$block_center_y    = ( $height * $y_position_percent ) / 100;
		$block_top_y       = $block_center_y - ( $total_text_height / 2 );
		$start_y           = $block_top_y - $line_bbox[7];

		foreach ( $lines as $index => $line ) {
			$bbox = imagettfbbox( $font_size, 0, $font_path, $line );
			if ( false === $bbox ) {
				continue;
			}

			$text_width = $bbox[2] - $bbox[0];
			$x          = $this->calculate_text_x( $alignment, $width, $text_width );
			$y          = (int) round( $start_y + ( $index * $line_height ) );

			if ( $y < 0 || $y > $height + $font_size ) {
				continue;
			}

			if ( ! empty( $shadow['enabled'] ) && null !== $shadow['color'] ) {
				imagettftext(
					$image,
					$font_size,
					0,
					$x + (int) $shadow['offset_x'],
					$y + (int) $shadow['offset_y'],
					$shadow['color'],
					$font_path,
					$line
				);
			}

			imagettftext( $image, $font_size, 0, $x, $y, $text_color, $font_path, $line );
		}

		return true;
	}

	/**
	 * Calculate a line's X coordinate.
	 *
	 * @param string $alignment  Alignment.
	 * @param int    $width      Image width.
	 * @param int    $text_width Text width.
	 * @return int
	 */
	private function calculate_text_x( $alignment, $width, $text_width ) {
		$margin = $width * 0.05;

		if ( 'left' === $alignment ) {
			return (int) round( $margin );
		}

		if ( 'right' === $alignment ) {
			return (int) round( $width - $margin - $text_width );
		}

		return (int) round( ( $width - $text_width ) / 2 );
	}

	/**
	 * Wrap text to fit a maximum width.
	 *
	 * @param string $text       Text.
	 * @param string $font_path  Font path.
	 * @param int    $font_size  Font size.
	 * @param float  $max_width  Maximum line width.
	 * @return array
	 */
	private function wrap_text( $text, $font_path, $font_size, $max_width ) {
		$text        = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$user_lines  = explode( "\n", $text );
		$final_lines = array();

		foreach ( $user_lines as $user_line ) {
			$words        = preg_split( '/\s+/u', trim( $user_line ) );
			$current_line = '';

			if ( empty( $words ) || false === $words ) {
				continue;
			}

			foreach ( $words as $word ) {
				if ( '' === $word ) {
					continue;
				}

				$test_line = '' === $current_line ? $word : $current_line . ' ' . $word;
				$bbox      = imagettfbbox( $font_size, 0, $font_path, $test_line );

				if ( false === $bbox ) {
					continue;
				}

				$test_width = $bbox[2] - $bbox[0];
				if ( $test_width <= $max_width || '' === $current_line ) {
					$current_line = $test_line;
				} else {
					$final_lines[] = $current_line;
					$current_line   = $word;
				}
			}

			if ( '' !== $current_line ) {
				$final_lines[] = $current_line;
			}
		}

		return $final_lines;
	}

	/**
	 * Allocate a color from a hex string.
	 *
	 * @param resource|\GdImage $image GD image.
	 * @param string            $hex   Hex color.
	 * @param int               $alpha GD alpha 0-127.
	 * @return int
	 */
	private function allocate_hex_color( $image, $hex, $alpha ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			$hex = 'ffffff';
		}

		$red   = hexdec( substr( $hex, 0, 2 ) );
		$green = hexdec( substr( $hex, 2, 2 ) );
		$blue  = hexdec( substr( $hex, 4, 2 ) );

		return imagecolorallocatealpha( $image, $red, $green, $blue, max( 0, min( 127, (int) $alpha ) ) );
	}

	/**
	 * Save the generated image and register it as an attachment.
	 *
	 * @param resource|\GdImage $image   GD image.
	 * @param string            $title   Card title.
	 * @param int               $post_id Parent post.
	 * @return array|\WP_Error
	 */
	private function save_generated_image( $image, $title, $post_id ) {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new \WP_Error( 'socialmcg_upload_dir_error', $upload_dir['error'] );
		}

		if ( ! wp_mkdir_p( $upload_dir['path'] ) ) {
			return new \WP_Error( 'socialmcg_upload_dir_create_failed', __( 'The WordPress uploads directory could not be created.', 'social-media-card-generator' ) );
		}

		$format    = (string) $this->get_option_value( 'socialmcg_output_format' );
		$extension = 'png' === $format ? 'png' : 'jpg';
		$mime_type = 'png' === $format ? 'image/png' : 'image/jpeg';
		$slug      = sanitize_title( wp_strip_all_tags( $title ) );
		$slug      = $slug ? substr( $slug, 0, 80 ) : 'social-card';
		$filename  = wp_unique_filename( $upload_dir['path'], sanitize_file_name( $slug . '-social-card.' . $extension ) );
		$filepath  = trailingslashit( $upload_dir['path'] ) . $filename;

		if ( 'png' === $format ) {
			imagealphablending( $image, true );
			imagesavealpha( $image, true );
			$saved = imagepng( $image, $filepath, 9 );
		} else {
			$saved = imagejpeg( $image, $filepath, (int) $this->get_option_value( 'socialmcg_jpeg_quality' ) );
		}

		if ( ! $saved ) {
			return new \WP_Error( 'socialmcg_image_save_failed', __( 'The generated image could not be saved to the uploads directory.', 'social-media-card-generator' ) );
		}

		$attachment = array(
			'guid'           => trailingslashit( $upload_dir['url'] ) . $filename,
			'post_mime_type' => $mime_type,
			'post_title'     => sprintf(
				/* translators: %s: Post/card title. */
				__( 'Social Card: %s', 'social-media-card-generator' ),
				$title
			),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $filepath, $post_id, true );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $filepath );
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $filepath );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		update_post_meta(
			$attachment_id,
			'_wp_attachment_image_alt',
			sprintf(
				/* translators: %s: Post/card title. */
				__( 'Social media card for %s', 'social-media-card-generator' ),
				$title
			)
		);

		$image_url = wp_get_attachment_url( $attachment_id );
		if ( ! $image_url ) {
			return new \WP_Error( 'socialmcg_attachment_url_error', __( 'The image was saved, but WordPress could not determine its attachment URL.', 'social-media-card-generator' ) );
		}

		return array(
			'image_url'           => esc_url_raw( $image_url ),
			'attachment_id'       => (int) $attachment_id,
			'attachment_edit_url' => esc_url_raw( get_edit_post_link( $attachment_id, 'raw' ) ),
			'filename'            => sanitize_file_name( $filename ),
		);
	}

	/**
	 * Get supported font choices.
	 *
	 * @return array
	 */
	private function get_font_choices() {
		$fonts = array(
			SOCIALMCG_DEFAULT_FONT => array(
				'label' => __( 'Open Sans Regular', 'social-media-card-generator' ),
				'path'  => SOCIALMCG_PLUGIN_DIR . 'fonts/OpenSans-Regular.ttf',
			),
		);

		$custom_dir = $this->get_custom_font_directory();
		if ( is_wp_error( $custom_dir ) || ! is_dir( $custom_dir['path'] ) ) {
			return $fonts;
		}

		$files = glob( trailingslashit( $custom_dir['path'] ) . '*.ttf' );
		if ( false === $files ) {
			return $fonts;
		}

		foreach ( $files as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}

			$basename = basename( $file );
			$key      = 'custom:' . $basename;
			$label    = pathinfo( $basename, PATHINFO_FILENAME );
			$label    = trim( preg_replace( '/[-_]+/', ' ', $label ) );

			$fonts[ $key ] = array(
				'label' => $label ? $label : $basename,
				'path'  => $file,
			);
		}

		return $fonts;
	}

	/**
	 * Resolve a font key to a safe local path.
	 *
	 * @param string $font_key Font key.
	 * @return string|false
	 */
	private function resolve_font_path( $font_key ) {
		$fonts = $this->get_font_choices();
		if ( isset( $fonts[ $font_key ] ) && is_readable( $fonts[ $font_key ]['path'] ) ) {
			return $fonts[ $font_key ]['path'];
		}

		if ( isset( $fonts[ SOCIALMCG_DEFAULT_FONT ] ) && is_readable( $fonts[ SOCIALMCG_DEFAULT_FONT ]['path'] ) ) {
			return $fonts[ SOCIALMCG_DEFAULT_FONT ]['path'];
		}

		return false;
	}

	/**
	 * Get the plugin's custom-font directory under uploads.
	 *
	 * @return array|\WP_Error
	 */
	private function get_custom_font_directory() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'socialmcg_upload_dir_error', $uploads['error'] );
		}

		$subdir = '/social-media-card-generator/fonts';
		return array(
			'path' => $uploads['basedir'] . $subdir,
			'url'  => $uploads['baseurl'] . $subdir,
		);
	}

	/**
	 * Filter uploads to the plugin's custom-font folder.
	 *
	 * @param array $dirs Upload paths.
	 * @return array
	 */
	public function filter_font_upload_dir( $dirs ) {
		$subdir         = '/social-media-card-generator/fonts';
		$dirs['subdir'] = $subdir;
		$dirs['path']   = $dirs['basedir'] . $subdir;
		$dirs['url']    = $dirs['baseurl'] . $subdir;
		return $dirs;
	}

	/**
	 * Temporarily allow TrueType fonts while the plugin handles its own font upload.
	 *
	 * This filter is registered only for the duration of the plugin's authenticated
	 * font-upload request. It does not enable font uploads in the Media Library.
	 *
	 * @param array $mimes Allowed MIME types.
	 * @return array
	 */
	public function allow_font_upload_mime( $mimes ) {
		$mimes['ttf'] = 'font/ttf';
		return $mimes;
	}

	/**
	 * Normalize WordPress file-type detection for a validated TrueType font.
	 *
	 * Modern PHP fileinfo databases do not agree on one MIME string for TTF files.
	 * WordPress therefore can reject a valid .ttf even when the upload handler is
	 * given a TTF MIME map. We only override that result after checking the file's
	 * extension, SFNT signature and FreeType readability.
	 *
	 * @param array        $data      Detected file data.
	 * @param string       $file      Temporary file path.
	 * @param string       $filename  Original filename.
	 * @param array|null   $mimes     Allowed MIME map.
	 * @param string|false $real_mime MIME detected by fileinfo.
	 * @return array
	 */
	public function filter_font_filetype_and_ext( $data, $file, $filename, $mimes, $real_mime ) {
		unset( $mimes, $real_mime );

		if ( 'ttf' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			return $data;
		}

		if ( ! $this->is_valid_truetype_font( $file ) ) {
			return $data;
		}

		$data['ext']             = 'ttf';
		$data['type']            = 'font/ttf';
		$data['proper_filename'] = false;

		return $data;
	}

	/**
	 * Validate that a local file is a usable TrueType font.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_valid_truetype_font( $path ) {
		if ( ! is_string( $path ) || ! is_readable( $path ) || ! function_exists( 'imagettfbbox' ) ) {
			return false;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a PHP-upload temporary file before WordPress moves it.
		if ( false === $handle ) {
			return false;
		}

		$signature = fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- See fopen explanation above.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See fopen explanation above.

		if ( ! in_array( $signature, array( "\x00\x01\x00\x00", 'true' ), true ) ) {
			return false;
		}

		$font_test = @imagettfbbox( 20, 0, $path, 'Social Media Card 123' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is used to reject invalid fonts.
		return false !== $font_test;
	}

	/**
	 * AJAX: upload a TTF font.
	 *
	 * @return void
	 */
	public function upload_font_callback() {
		check_ajax_referer( 'socialmcg_font_management_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage plugin fonts.', 'social-media-card-generator' ) ), 403 );
		}

		if ( ! function_exists( 'imagettfbbox' ) ) {
			wp_send_json_error( array( 'message' => __( 'GD with FreeType support is required before a custom font can be validated.', 'social-media-card-generator' ) ), 500 );
		}

		if ( empty( $_FILES['font_file'] ) || ! is_array( $_FILES['font_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a .ttf font file to upload.', 'social-media-card-generator' ) ), 400 );
		}

		$file = $_FILES['font_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passed to WordPress upload handling after strict validation.

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			wp_send_json_error( array( 'message' => __( 'The font upload did not complete successfully.', 'social-media-card-generator' ) ), 400 );
		}

		$original_name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		if ( 'ttf' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Only TrueType (.ttf) font files are supported.', 'social-media-card-generator' ) ), 400 );
		}

		if ( empty( $file['size'] ) || (int) $file['size'] > SOCIALMCG_MAX_FONT_SIZE_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'Font files must be no larger than 5 MB.', 'social-media-card-generator' ) ), 400 );
		}

		$font_dir = $this->get_custom_font_directory();
		if ( is_wp_error( $font_dir ) ) {
			wp_send_json_error( array( 'message' => $font_dir->get_error_message() ), 500 );
		}

		if ( ! wp_mkdir_p( $font_dir['path'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The custom font directory could not be created.', 'social-media-card-generator' ) ), 500 );
		}

		if ( empty( $file['tmp_name'] ) || ! $this->is_valid_truetype_font( $file['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected file is not a readable TrueType font. Please upload a valid .ttf file.', 'social-media-card-generator' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		add_filter( 'upload_dir', array( $this, 'filter_font_upload_dir' ) );
		add_filter( 'upload_mimes', array( $this, 'allow_font_upload_mime' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'filter_font_filetype_and_ext' ), 10, 5 );
		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array( 'ttf' => 'font/ttf' ),
			)
		);
		remove_filter( 'wp_check_filetype_and_ext', array( $this, 'filter_font_filetype_and_ext' ), 10 );
		remove_filter( 'upload_mimes', array( $this, 'allow_font_upload_mime' ) );
		remove_filter( 'upload_dir', array( $this, 'filter_font_upload_dir' ) );

		if ( ! empty( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
			$message = ! empty( $uploaded['error'] ) ? $uploaded['error'] : __( 'The font could not be uploaded.', 'social-media-card-generator' );
			wp_send_json_error( array( 'message' => sanitize_text_field( $message ) ), 400 );
		}

		$uploaded_path = $uploaded['file'];
		$font_test     = @imagettfbbox( 20, 0, $uploaded_path, 'Social Media Card 123' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is used to reject invalid fonts.
		if ( false === $font_test ) {
			wp_delete_file( $uploaded_path );
			wp_send_json_error( array( 'message' => __( 'The uploaded file is not a readable TrueType font for this server.', 'social-media-card-generator' ) ), 400 );
		}

		$key   = 'custom:' . basename( $uploaded_path );
		$fonts = $this->get_font_choices();
		$label = isset( $fonts[ $key ]['label'] ) ? $fonts[ $key ]['label'] : basename( $uploaded_path );

		wp_send_json_success(
			array(
				'message' => __( 'Font uploaded successfully.', 'social-media-card-generator' ),
				'font'    => array(
					'key'   => sanitize_text_field( $key ),
					'label' => sanitize_text_field( $label ),
				),
			)
		);
	}

	/**
	 * AJAX: delete a custom font.
	 *
	 * @return void
	 */
	public function delete_font_callback() {
		check_ajax_referer( 'socialmcg_font_management_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage plugin fonts.', 'social-media-card-generator' ) ), 403 );
		}

		$key = isset( $_POST['font_key'] ) ? sanitize_text_field( wp_unslash( $_POST['font_key'] ) ) : '';
		if ( 0 !== strpos( $key, 'custom:' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bundled fonts cannot be deleted.', 'social-media-card-generator' ) ), 400 );
		}

		$basename = sanitize_file_name( substr( $key, strlen( 'custom:' ) ) );
		if ( '' === $basename || 'ttf' !== strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid font selection.', 'social-media-card-generator' ) ), 400 );
		}

		$font_dir = $this->get_custom_font_directory();
		if ( is_wp_error( $font_dir ) ) {
			wp_send_json_error( array( 'message' => $font_dir->get_error_message() ), 500 );
		}

		$path = trailingslashit( $font_dir['path'] ) . $basename;
		if ( ! is_file( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected custom font no longer exists.', 'social-media-card-generator' ) ), 404 );
		}

		$real_file = realpath( $path );
		$real_dir  = realpath( $font_dir['path'] );
		if ( false === $real_file || false === $real_dir || 0 !== strpos( $real_file, trailingslashit( $real_dir ) ) ) {
			wp_send_json_error( array( 'message' => __( 'The font path could not be validated.', 'social-media-card-generator' ) ), 400 );
		}

		wp_delete_file( $real_file );
		if ( file_exists( $real_file ) ) {
			wp_send_json_error( array( 'message' => __( 'The font file could not be deleted.', 'social-media-card-generator' ) ), 500 );
		}

		if ( $key === $this->get_option_value( 'socialmcg_title_font' ) ) {
			update_option( 'socialmcg_title_font', SOCIALMCG_DEFAULT_FONT );
		}
		if ( $key === $this->get_option_value( 'socialmcg_description_font' ) ) {
			update_option( 'socialmcg_description_font', SOCIALMCG_DEFAULT_FONT );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Custom font deleted.', 'social-media-card-generator' ),
				'font_key' => $key,
			)
		);
	}

	/**
	 * Render custom font rows.
	 *
	 * @return void
	 */
	private function render_custom_font_list() {
		$fonts  = $this->get_font_choices();
		$custom = array();

		foreach ( $fonts as $key => $font ) {
			if ( 0 === strpos( $key, 'custom:' ) ) {
				$custom[ $key ] = $font;
			}
		}

		if ( empty( $custom ) ) {
			echo '<p class="socialmcg-empty-fonts">' . esc_html__( 'No custom fonts uploaded.', 'social-media-card-generator' ) . '</p>';
			return;
		}

		foreach ( $custom as $key => $font ) {
			?>
			<div class="socialmcg-font-row" data-font-key="<?php echo esc_attr( $key ); ?>">
				<span><?php echo esc_html( $font['label'] ); ?></span>
				<button type="button" class="button-link-delete socialmcg-delete-font" data-font-key="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Delete', 'social-media-card-generator' ); ?></button>
			</div>
			<?php
		}
	}

	/**
	 * Inspect AIOSEO availability.
	 *
	 * @return array
	 */
	private function get_aioseo_status() {
		if ( function_exists( 'aioseo' ) ) {
			$version = defined( 'AIOSEO_VERSION' ) ? AIOSEO_VERSION : '';
			$class   = '\\AIOSEO\\Plugin\\Common\\Models\\Post';

			if ( ! class_exists( $class ) || ! is_callable( array( $class, 'getPost' ) ) ) {
				return array( 'state' => 'unsupported', 'version' => $version );
			}

			return array( 'state' => 'active', 'version' => $version );
		}

		$installed = file_exists( WP_PLUGIN_DIR . '/all-in-one-seo-pack/all_in_one_seo_pack.php' );
		if ( ! $installed ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( get_plugins() as $plugin_file => $plugin_data ) {
				$text_domain = isset( $plugin_data['TextDomain'] ) ? (string) $plugin_data['TextDomain'] : '';
				if ( 'all-in-one-seo-pack' === $text_domain || 0 === strpos( $plugin_file, 'all-in-one-seo-pack/' ) ) {
					$installed = true;
					break;
				}
			}
		}

		return array( 'state' => $installed ? 'inactive' : 'absent', 'version' => '' );
	}

	/**
	 * AJAX: remember the current user's AIOSEO social-image preference.
	 *
	 * @return void
	 */
	public function save_aioseo_preference_callback() {
		check_ajax_referer( 'socialmcg_aioseo_preference_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this preference.', 'social-media-card-generator' ) ), 403 );
		}

		$enabled = isset( $_POST['enabled'] ) && 1 === absint( wp_unslash( $_POST['enabled'] ) );
		update_user_meta( get_current_user_id(), 'socialmcg_aioseo_social_images', $enabled ? 1 : 0 );

		wp_send_json_success();
	}

	/**
	 * Update AIOSEO's Facebook and Twitter custom images for a post.
	 *
	 * AIOSEO versions before 4.9.8 expect a complete payload in Post::savePost().
	 * Passing only the social-image fields can therefore reset unrelated SEO data
	 * or trigger errors. Updating the already-loaded Post model preserves all
	 * existing AIOSEO values while changing only the social-image columns.
	 *
	 * @param int    $post_id       Post ID.
	 * @param int    $attachment_id Generated Media Library attachment ID.
	 * @param string $image_url     Generated image URL.
	 * @return true|\WP_Error
	 */
	private function update_aioseo_social_images( $post_id, $attachment_id, $image_url ) {
		$status = $this->get_aioseo_status();
		if ( 'active' !== $status['state'] ) {
			return new \WP_Error( 'socialmcg_aioseo_unavailable', __( 'AIOSEO is not active or does not provide the required integration API.', 'social-media-card-generator' ) );
		}

		$image_url = esc_url_raw( $image_url );
		if ( '' === $image_url ) {
			return new \WP_Error( 'socialmcg_aioseo_invalid_url', __( 'The image was saved, but its URL could not be passed to AIOSEO.', 'social-media-card-generator' ) );
		}

		$class = '\\AIOSEO\\Plugin\\Common\\Models\\Post';

		try {
			$aioseo_post = $class::getPost( $post_id );
			if ( ! is_object( $aioseo_post ) || ! is_callable( array( $aioseo_post, 'save' ) ) ) {
				return new \WP_Error( 'socialmcg_aioseo_model_unavailable', __( 'The image was saved, but AIOSEO did not return a writable post model.', 'social-media-card-generator' ) );
			}

			// AIOSEO renders per-post social images only when these sources are custom_image.
			$aioseo_post->og_image_type            = 'custom_image';
			$aioseo_post->og_image_custom_url      = $image_url;
			$aioseo_post->twitter_use_og           = false;
			$aioseo_post->twitter_image_type       = 'custom_image';
			$aioseo_post->twitter_image_custom_url = $image_url;

			// Keep AIOSEO's derived image columns in sync without forcing a full savePost() payload.
			$aioseo_post->og_image_url      = $image_url;
			$aioseo_post->twitter_image_url = $image_url;

			$image_src = wp_get_attachment_image_src( absint( $attachment_id ), 'full' );
			if ( is_array( $image_src ) ) {
				$aioseo_post->og_image_width  = absint( $image_src[1] );
				$aioseo_post->og_image_height = absint( $image_src[2] );
			}

			$aioseo_post->save();

			if ( property_exists( $aioseo_post, 'lastError' ) && ! empty( $aioseo_post->lastError ) ) {
				return new \WP_Error( 'socialmcg_aioseo_save_error', __( 'The image was saved, but AIOSEO reported a database error while updating the social images.', 'social-media-card-generator' ) );
			}

			// Verify the persisted values instead of assuming the model write succeeded.
			$saved_post = $class::getPost( $post_id );
			if (
				! is_object( $saved_post ) ||
				'custom_image' !== (string) $saved_post->og_image_type ||
				$image_url !== (string) $saved_post->og_image_custom_url ||
				'custom_image' !== (string) $saved_post->twitter_image_type ||
				$image_url !== (string) $saved_post->twitter_image_custom_url ||
				(bool) $saved_post->twitter_use_og
			) {
				return new \WP_Error( 'socialmcg_aioseo_verify_error', __( 'The image was saved, but AIOSEO did not persist the Facebook and Twitter custom-image settings.', 'social-media-card-generator' ) );
			}

			// AIOSEO caches post meta data internally. Bust it when that API is available.
			$aioseo = aioseo();
			if (
				isset( $aioseo->meta->metaData ) &&
				is_callable( array( $aioseo->meta->metaData, 'bustPostCache' ) )
			) {
				$aioseo->meta->metaData->bustPostCache( $post_id, $saved_post );
			}

			do_action( 'aioseo_insert_post', $post_id );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'socialmcg_aioseo_exception', __( 'The image was saved, but AIOSEO could not be updated.', 'social-media-card-generator' ) );
		}

		return true;
	}

	/**
	 * Enqueue admin assets only where needed.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_settings = 'settings_page_social-media-card-generator' === $screen->id;
		$is_post     = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) && 'post' === $screen->post_type;

		if ( ! $is_settings && ! $is_post ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'socialmcg-admin', SOCIALMCG_PLUGIN_URL . 'assets/admin.css', array(), SOCIALMCG_VERSION );

		$script_dependencies = array( 'jquery' );
		if ( $is_post ) {
			// Used for iframe-safe title synchronization in the block editor. The
			// DOM fallback in admin.js continues to support Classic Editor.
			$script_dependencies[] = 'wp-data';
		}
		if ( $is_settings ) {
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			$script_dependencies[] = 'wp-color-picker';
		}

		wp_enqueue_script( 'socialmcg-admin', SOCIALMCG_PLUGIN_URL . 'assets/admin.js', $script_dependencies, SOCIALMCG_VERSION, true );

		$post_id = 0;
		if ( $is_post ) {
			$post_id = get_the_ID();
		}

		wp_localize_script(
			'socialmcg-admin',
			'socialmcgParams',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'generateNonce'    => wp_create_nonce( 'socialmcg_generate_image_nonce' ),
				'fontNonce'        => wp_create_nonce( 'socialmcg_font_management_nonce' ),
				'aioseoPrefNonce'  => wp_create_nonce( 'socialmcg_aioseo_preference_nonce' ),
				'postId'           => $post_id ? (int) $post_id : 0,
				'isSettingsPage'   => $is_settings,
				'isPostEditPage'   => $is_post,
				'defaultFontKey'   => SOCIALMCG_DEFAULT_FONT,
				'i18n'             => array(
					'selectTemplateImage' => __( 'Select a Template Image', 'social-media-card-generator' ),
					'useThisImage'        => __( 'Use this image', 'social-media-card-generator' ),
					'noTemplate'          => __( 'No template selected yet.', 'social-media-card-generator' ),
					'titleRequired'       => __( 'Title is required.', 'social-media-card-generator' ),
					'saveDraftFirst'      => __( 'Please save this post as a draft before generating a card.', 'social-media-card-generator' ),
					'errorOccurred'       => __( 'Something went wrong while generating the card.', 'social-media-card-generator' ),
					'requestTimedOut'     => __( 'The request timed out. Please try again.', 'social-media-card-generator' ),
					'preview'             => __( 'Preview', 'social-media-card-generator' ),
					'socialCardPreview'   => __( 'Generated social media card', 'social-media-card-generator' ),
					'savedPrefix'         => __( 'Success — ', 'social-media-card-generator' ),
					'savedSuffix'         => __( ' has been saved to your Media Library.', 'social-media-card-generator' ),
					'chooseFont'          => __( 'Choose a .ttf font file first.', 'social-media-card-generator' ),
					'uploadingFont'       => __( 'Uploading…', 'social-media-card-generator' ),
					'deleteFontConfirm'   => __( 'Delete this custom font? Any unsaved font selections using it will return to Open Sans.', 'social-media-card-generator' ),
					'delete'              => __( 'Delete', 'social-media-card-generator' ),
					'uploadFont'          => __( 'Upload Font', 'social-media-card-generator' ),
					'noCustomFonts'       => __( 'No custom fonts uploaded.', 'social-media-card-generator' ),
					'openSansSelected'     => __( 'Open Sans selected. Save Changes to apply it.', 'social-media-card-generator' ),
				),
			)
		);
	}
}
