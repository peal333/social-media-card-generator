<?php
/**
 * Plugin Name:       Social Media Card Generator
 * Description:       Allows users to easily create custom social media cards for posts directly within the WordPress Post Creation/Edit page.
 * Version:           1.0
 * Author:            Panupan Sriautharawong
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-media-card-generator
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define constants
define('SMCG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SMCG_FONT_PATH', SMCG_PLUGIN_DIR . 'fonts/OpenSans-Regular.ttf');
define('SMCG_MAX_IMAGE_SIZE', 5 * 1024 * 1024); // 5MB limit
define('SMCG_MEMORY_LIMIT', '256M');

class SocialMediaCardGeneratorPlugin {

    /**
     * Constructor to hook everything into WordPress.
     */
    public function __construct() {
        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Settings Page
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Post Meta Box
        add_action('add_meta_boxes', [$this, 'add_meta_box']);

        // Scripts and Styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_media_assets']);
        add_action('admin_head', [$this, 'inject_admin_styles']);
        add_action('admin_footer', [$this, 'inject_admin_scripts']);

        // AJAX handler for image generation
        add_action('wp_ajax_smcg_generate_image', [$this, 'generate_image_callback']);
        
        // Admin notices
        add_action('admin_notices', [$this, 'check_requirements']);
    }

    /**
     * Plugin activation hook.
     */
    public function activate() {
        // Create fonts directory if it doesn't exist
        $fonts_dir = SMCG_PLUGIN_DIR . 'fonts';
        if (!file_exists($fonts_dir)) {
            wp_mkdir_p($fonts_dir);
        }
        
        // Set default options
        add_option('smcg_title_font_size', 82);
        add_option('smcg_description_font_size', 42);
        add_option('smcg_title_y_position', 50);
        add_option('smcg_description_y_position', 88);
        add_option('smcg_output_format', 'jpeg');
        add_option('smcg_jpeg_quality', 70);
    }

    /**
     * Plugin deactivation hook.
     */
    public function deactivate() {
        // Clean up any temporary files or transients if needed
        delete_transient('smcg_font_check');
    }

    /**
     * Check system requirements and display admin notices.
     */
    public function check_requirements() {
        $notices = [];
        
        if (!extension_loaded('gd')) {
            $notices[] = __('The GD library is required for image generation but is not installed on your server.', 'social-media-card-generator');
        }
        
        $font_check = get_transient('smcg_font_check');
        if (false === $font_check) {
            $font_check = file_exists(SMCG_FONT_PATH) ? 'exists' : 'missing';
            set_transient('smcg_font_check', $font_check, HOUR_IN_SECONDS);
        }
        
        if ($font_check === 'missing') {
            $notices[] = sprintf(
                __('For best results, please upload %s to the %s directory. A system font will be used as fallback.', 'social-media-card-generator'),
                '<code>OpenSans-Regular.ttf</code>',
                '<code>wp-content/plugins/social-media-card-generator/fonts/</code>'
            );
        }
        
        foreach ($notices as $notice) {
            printf('<div class="notice notice-warning"><p><strong>%s:</strong> %s</p></div>', 
                   esc_html__('Social Media Card Generator', 'social-media-card-generator'), 
                   $notice);
        }
    }

    /**
     * Get available font path with fallback.
     */
    private function get_font_path() {
        if (file_exists(SMCG_FONT_PATH)) {
            return SMCG_FONT_PATH;
        }
        
        $system_fonts = [
            '/System/Library/Fonts/Arial.ttf', '/System/Library/Fonts/Helvetica.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/Windows/Fonts/arial.ttf', '/Windows/Fonts/calibri.ttf',
        ];
        
        foreach ($system_fonts as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }
        
        return false;
    }

    //======================================================================
    // 1. PLUGIN SETTINGS PAGE
    //======================================================================

    public function add_settings_page() {
        add_options_page(
            __('Social Media Card Generator', 'social-media-card-generator'),
            __('Social Media Card Generator', 'social-media-card-generator'),
            'manage_options',
            'social-media-card-generator',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Social Media Card Generator Settings', 'social-media-card-generator'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('smcg_settings_group');
                do_settings_sections('social-media-card-generator');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        // Main Settings Section
        add_settings_section('smcg_settings_section', __('Layout & Style Settings', 'social-media-card-generator'), null, 'social-media-card-generator');

        register_setting('smcg_settings_group', 'smcg_template_image_id', ['type' => 'number', 'sanitize_callback' => 'absint']);
        add_settings_field('smcg_template_image_id', __('Template Image', 'social-media-card-generator'), [$this, 'render_template_image_field'], 'social-media-card-generator', 'smcg_settings_section');
        
        register_setting('smcg_settings_group', 'smcg_title_font_size', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_font_size'], 'default' => 82]);
        add_settings_field('smcg_title_font_size', __('Title Font Size (px)', 'social-media-card-generator'), [$this, 'render_font_size_field'], 'social-media-card-generator', 'smcg_settings_section', ['name' => 'smcg_title_font_size', 'default' => 82]);
        
        register_setting('smcg_settings_group', 'smcg_title_y_position', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_position_percentage'], 'default' => 50]);
        add_settings_field('smcg_title_y_position', __('Title Y-Position (%)', 'social-media-card-generator'), [$this, 'render_position_field'], 'social-media-card-generator', 'smcg_settings_section', ['name' => 'smcg_title_y_position', 'default' => 50, 'desc' => __('Position from the top. 50% is perfectly centered.', 'social-media-card-generator')]);

        register_setting('smcg_settings_group', 'smcg_description_font_size', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_font_size'], 'default' => 42]);
        add_settings_field('smcg_description_font_size', __('Description Font Size (px)', 'social-media-card-generator'), [$this, 'render_font_size_field'], 'social-media-card-generator', 'smcg_settings_section', ['name' => 'smcg_description_font_size', 'default' => 42]);

        register_setting('smcg_settings_group', 'smcg_description_y_position', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_position_percentage'], 'default' => 88]);
        add_settings_field('smcg_description_y_position', __('Description Y-Position (%)', 'social-media-card-generator'), [$this, 'render_position_field'], 'social-media-card-generator', 'smcg_settings_section', ['name' => 'smcg_description_y_position', 'default' => 88, 'desc' => __('Position from the top.', 'social-media-card-generator')]);

        // Output Settings Section
        add_settings_section('smcg_output_settings_section', __('Output Settings', 'social-media-card-generator'), null, 'social-media-card-generator');

        register_setting('smcg_settings_group', 'smcg_output_format', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_output_format'], 'default' => 'jpeg']);
        add_settings_field('smcg_output_format', __('Output Format', 'social-media-card-generator'), [$this, 'render_output_format_field'], 'social-media-card-generator', 'smcg_output_settings_section');

        register_setting('smcg_settings_group', 'smcg_jpeg_quality', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_jpeg_quality'], 'default' => 70]);
        add_settings_field('smcg_jpeg_quality', __('JPEG Quality', 'social-media-card-generator'), [$this, 'render_jpeg_quality_field'], 'social-media-card-generator', 'smcg_output_settings_section');
    }
    
    public function sanitize_output_format($input) {
        $allowed = ['png', 'jpeg'];
        return in_array($input, $allowed) ? $input : 'jpeg';
    }

    public function sanitize_jpeg_quality($input) {
        $input = absint($input);
        return ($input >= 1 && $input <= 100) ? $input : 70;
    }

    public function sanitize_font_size($input) {
        $input = absint($input);
        return ($input >= 10 && $input <= 300) ? $input : 82;
    }

    public function sanitize_position_percentage($input) {
        $input = absint($input);
        return ($input >= 0 && $input <= 100) ? $input : 50;
    }

    public function render_output_format_field() {
        $current_format = get_option('smcg_output_format', 'jpeg');
        ?>
        <select id="smcg_output_format" name="smcg_output_format">
            <option value="jpeg" <?php selected($current_format, 'jpeg'); ?>>JPEG</option>
            <option value="png" <?php selected($current_format, 'png'); ?>>PNG</option>
        </select>
        <p class="description"><?php esc_html_e('Choose JPEG for smaller file sizes or PNG for higher quality.', 'social-media-card-generator'); ?></p>
        <?php
    }

    public function render_jpeg_quality_field() {
        $quality = get_option('smcg_jpeg_quality', 70);
        printf(
            '<input type="number" id="smcg_jpeg_quality" name="smcg_jpeg_quality" value="%s" min="1" max="100" />',
            esc_attr($quality)
        );
        printf('<p class="description">%s</p>', esc_html__('For JPEG format only. A value between 1 (low quality) and 100 (best quality).', 'social-media-card-generator'));
    }

    public function render_template_image_field() {
        $image_id = get_option('smcg_template_image_id');
        echo '<input type="hidden" id="smcg_template_image_id" name="smcg_template_image_id" value="' . esc_attr($image_id) . '">';
        echo '<button type="button" class="button" id="smcg_upload_image_button">' . esc_html__('Select Image', 'social-media-card-generator') . '</button>';
        echo '<div id="smcg_template_image_preview" style="margin-top: 15px;">';
        if ($image_id) {
            echo wp_get_attachment_image($image_id, 'medium');
        }
        echo '</div>';
    }

    public function render_font_size_field($args) {
        $option_value = get_option($args['name'], $args['default']);
        printf(
            '<input type="number" name="%s" value="%s" min="10" max="300" />',
            esc_attr($args['name']),
            esc_attr($option_value)
        );
    }
    
    public function render_position_field($args) {
        $option_value = get_option($args['name'], $args['default']);
        printf(
            '<input type="number" name="%s" value="%s" min="0" max="100" step="1" /> %%',
            esc_attr($args['name']),
            esc_attr($option_value)
        );
        if (!empty($args['desc'])) {
            printf('<p class="description">%s</p>', esc_html($args['desc']));
        }
    }

    //======================================================================
    // 2. POST META BOX
    //======================================================================
    
    public function add_meta_box() {
        add_meta_box('smcg_meta_box', __('Social Media Card Generator', 'social-media-card-generator'), [$this, 'render_meta_box'], 'post', 'side', 'high');
    }

    public function render_meta_box($post) {
        ?>
        <div class="smcg-meta-box-wrapper">
            <p><label for="smcg_title"><strong><?php esc_html_e('Title', 'social-media-card-generator'); ?></strong></label>
                <input type="text" id="smcg_title" name="smcg_title" value="<?php echo esc_attr(get_the_title($post->ID)); ?>" style="width:100%;" required></p>
            <p><label for="smcg_description"><strong><?php esc_html_e('Description (Optional)', 'social-media-card-generator'); ?></strong></label>
                <textarea id="smcg_description" name="smcg_description" style="width:100%;" rows="3" maxlength="200"></textarea></p>
            <button type="button" id="smcg_generate_button" class="button button-primary is-full-width"><?php esc_html_e('Generate Card', 'social-media-card-generator'); ?></button>
            <div id="smcg_loader" style="display:none; text-align: center; margin: 10px 0;"><span class="spinner is-active"></span> <?php esc_html_e('Generating...', 'social-media-card-generator'); ?></div>
            <div id="smcg_error" style="color:red; margin-top:10px; font-weight: bold;"></div>
            <div id="smcg_image_preview" style="margin-top:15px; max-width: 100%; height: auto;"><p class="description"><?php esc_html_e('Preview of the generated card will appear here.', 'social-media-card-generator'); ?></p></div>
        </div>
        <?php
    }

    //======================================================================
    // 3. IMAGE GENERATION
    //======================================================================
    
    public function generate_image_callback() {
        if (!check_ajax_referer('smcg_generate_image_nonce', 'nonce', false)) wp_send_json_error(['message' => __('Security check failed.', 'social-media-card-generator')]);
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => __('Insufficient permissions.', 'social-media-card-generator')]);
        if (!extension_loaded('gd')) wp_send_json_error(['message' => __('GD library is not available on this server.', 'social-media-card-generator')]);
        
        $font_path = $this->get_font_path();
        if (!$font_path) wp_send_json_error(['message' => __('No suitable font found on the server.', 'social-media-card-generator')]);
        
        ini_set('memory_limit', SMCG_MEMORY_LIMIT);
        
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        
        if (empty($title) || $post_id === 0) wp_send_json_error(['message' => __('Missing required data (title or post ID).', 'social-media-card-generator')]);
        
        $template_id = get_option('smcg_template_image_id');
        if (!$template_id) wp_send_json_error(['message' => __('No template image selected. Please set one in the plugin settings.', 'social-media-card-generator')]);
        
        $template_path = get_attached_file($template_id);
        if (!$template_path || !file_exists($template_path)) wp_send_json_error(['message' => __('Template image file cannot be found.', 'social-media-card-generator')]);
        if (filesize($template_path) > SMCG_MAX_IMAGE_SIZE) wp_send_json_error(['message' => __('Template image is too large. Maximum size is 5MB.', 'social-media-card-generator')]);
        
        $result = $this->create_social_card_image($template_path, $title, $description, $post_id, $font_path);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
        
        wp_send_json_success($result);
    }

    private function create_social_card_image($template_path, $title, $description, $post_id, $font_path) {
        $image_info = @getimagesize($template_path);
        if (!$image_info) return new WP_Error('invalid_image', __('Invalid or corrupted template image.', 'social-media-card-generator'));
        
        $image = null;
        switch ($image_info['mime']) {
            case 'image/jpeg': $image = @imagecreatefromjpeg($template_path); break;
            case 'image/png': $image = @imagecreatefrompng($template_path); break;
            case 'image/gif': $image = @imagecreatefromgif($template_path); break;
            case 'image/webp': if (function_exists('imagecreatefromwebp')) $image = @imagecreatefromwebp($template_path); break;
        }
        
        if (!$image) return new WP_Error('image_creation_failed', __('Failed to create image resource.', 'social-media-card-generator'));
        
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 100 || $height < 100) {
            imagedestroy($image);
            return new WP_Error('image_too_small', __('Template image is too small. Minimum size is 100x100 pixels.', 'social-media-card-generator'));
        }
        
        $title_font_size = absint(get_option('smcg_title_font_size', 82));
        $desc_font_size = absint(get_option('smcg_description_font_size', 42));
        $title_y_pos = absint(get_option('smcg_title_y_position', 50));
        $desc_y_pos = absint(get_option('smcg_description_y_position', 88));

        $text_color = imagecolorallocatealpha($image, 255, 255, 255, 0);
        $shadow_color = imagecolorallocatealpha($image, 0, 0, 0, 50);
        
        $title_result = $this->add_text_to_image($image, $title, $font_path, $title_font_size, $text_color, $shadow_color, $width, $height, $title_y_pos);
        if (is_wp_error($title_result)) {
            imagedestroy($image);
            return $title_result;
        }
        
        if (!empty($description)) {
            $desc_result = $this->add_text_to_image($image, $description, $font_path, $desc_font_size, $text_color, $shadow_color, $width, $height, $desc_y_pos);
            if (is_wp_error($desc_result)) {
                imagedestroy($image);
                return $desc_result;
            }
        }
        
        $save_result = $this->save_generated_image($image, $title, $post_id);
        imagedestroy($image);
        
        return $save_result;
    }

    private function add_text_to_image($image, $text, $font_path, $font_size, $text_color, $shadow_color, $width, $height, $y_position_percent) {
        $max_width = $width * 0.9;
        $lines = $this->wrap_text($text, $font_path, $font_size, $max_width);
        
        if (empty($lines)) return new WP_Error('text_wrap_failed', __('Failed to wrap text.', 'social-media-card-generator'));
        
        $line_height = $font_size * 1.25;
        $total_text_height = count($lines) * $line_height;

        if ($y_position_percent == 50) {
            $start_y = (($height - $total_text_height) / 2) + $font_size;
        } else {
            $start_y = ($height * $y_position_percent) / 100;
        }
        
        $start_y = max($font_size, $start_y);
        
        foreach ($lines as $i => $line) {
            $bbox = imagettfbbox($font_size, 0, $font_path, $line);
            $text_width = $bbox[2] - $bbox[0];
            $x = ($width - $text_width) / 2;
            $y = $start_y + ($i * $line_height);
            
            if ($y > $height - ($font_size / 2)) continue;

            imagettftext($image, $font_size, 0, $x + 2, $y + 2, $shadow_color, $font_path, $line);
            imagettftext($image, $font_size, 0, $x, $y, $text_color, $font_path, $line);
        }
        
        return true;
    }

    private function wrap_text($text, $font_path, $font_size, $max_width) {
        $words = explode(' ', $text);
        $lines = [];
        $current_line = '';
        
        foreach ($words as $word) {
            $test_line = empty($current_line) ? $word : $current_line . ' ' . $word;
            $bbox = imagettfbbox($font_size, 0, $font_path, $test_line);
            $test_width = $bbox[2] - $bbox[0];
            
            if ($test_width <= $max_width) {
                $current_line = $test_line;
            } else {
                if (!empty($current_line)) $lines[] = $current_line;
                $current_line = $word;
            }
        }
        
        if (!empty($current_line)) $lines[] = $current_line;
        
        return $lines;
    }

    private function save_generated_image($image, $title, $post_id) {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) return new WP_Error('upload_dir_error', $upload_dir['error']);

        $format = get_option('smcg_output_format', 'jpeg');
        $quality = absint(get_option('smcg_jpeg_quality', 70));
        
        $extension = ($format === 'png') ? 'png' : 'jpg';
        $mime_type = ($format === 'png') ? 'image/png' : 'image/jpeg';
        
        $filename = sanitize_file_name(sanitize_title($title) . '-social-card-' . time() . '.' . $extension);
        $filepath = $upload_dir['path'] . '/' . $filename;
        
        $saved_successfully = false;
        if ($format === 'png') {
            $saved_successfully = imagepng($image, $filepath, 9);
        } else {
            $saved_successfully = imagejpeg($image, $filepath, $quality);
        }

        if (!$saved_successfully) return new WP_Error('image_save_failed', __('Failed to save the generated image.', 'social-media-card-generator'));
        
        $attachment = [
            'guid'           => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => $mime_type,
            'post_title'     => sprintf(__('Social Card: %s', 'social-media-card-generator'), $title),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        
        $attach_id = wp_insert_attachment($attachment, $filepath, $post_id);
        if (is_wp_error($attach_id)) {
            @unlink($filepath);
            return $attach_id;
        }
        
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        return ['image_url' => wp_get_attachment_url($attach_id), 'attach_id' => $attach_id];
    }

    //======================================================================
    // 4. ASSETS (CSS & JAVASCRIPT)
    //======================================================================
    
    public function enqueue_media_assets($hook) {
        $current_screen = get_current_screen();
        if ('post' === $current_screen->base || strpos($hook, 'social-media-card-generator') !== false) {
            wp_enqueue_media();
        }
    }
    
    public function inject_admin_styles() {
        $screen = get_current_screen();
        if ('post' !== $screen->base && strpos($screen->id, 'social-media-card-generator') === false) return;
        ?>
        <style>
            #smcg_template_image_preview img { margin-top: 10px; border: 1px solid #ddd; padding: 5px; max-width: 100%; height: auto; }
            .smcg-meta-box-wrapper .button.is-full-width { width: 100%; margin-bottom: 10px; }
            #smcg_image_preview img { border: 1px solid #ddd; max-width: 100%; height: auto; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            #smcg_loader .spinner { float: none; vertical-align: middle; margin-right: 5px; }
            .smcg-meta-box-wrapper textarea { resize: vertical; min-height: 60px; }
            .form-table td p.description { margin-top: 4px; }
            .settings_page_social-media-card-generator .form-table tr.hidden { display: none; }
        </style>
        <?php
    }

    public function inject_admin_scripts() {
        $screen = get_current_screen();
        if ('post' !== $screen->base && strpos($screen->id, 'social-media-card-generator') === false) return;
        
        $ajax_nonce = wp_create_nonce('smcg_generate_image_nonce');
        $post_id = get_the_ID() ?: 0;
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // SCRIPT FOR SETTINGS PAGE
            if ($('body').hasClass('settings_page_social-media-card-generator')) {
                $('#smcg_upload_image_button').on('click', function(e) {
                    e.preventDefault();
                    var image_frame = wp.media({
                        title: '<?php echo esc_js(__('Select a Template Image', 'social-media-card-generator')); ?>',
                        button: { text: '<?php echo esc_js(__('Use this image', 'social-media-card-generator')); ?>' },
                        multiple: false, library: { type: 'image' }
                    });
                    image_frame.on('select', function() {
                        var attachment = image_frame.state().get('selection').first().toJSON();
                        $('#smcg_template_image_id').val(attachment.id);
                        var imageUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                        $('#smcg_template_image_preview').html('<img src="' + imageUrl + '" alt="<?php echo esc_js(__('Template Preview', 'social-media-card-generator')); ?>">');
                    });
                    image_frame.open();
                });

                var $outputFormat = $('#smcg_output_format');
                var $jpegQualityRow = $('#smcg_jpeg_quality').closest('tr');

                function toggleQualityField() {
                    if ($outputFormat.val() === 'jpeg') {
                        $jpegQualityRow.removeClass('hidden');
                    } else {
                        $jpegQualityRow.addClass('hidden');
                    }
                }
                toggleQualityField();
                $outputFormat.on('change', toggleQualityField);
            }

            // SCRIPT FOR POST META BOX
            if ($('body').hasClass('post-php') || $('body').hasClass('post-new-php')) {
                $('#title').on('input', function() { $('#smcg_title').val($(this).val()); });

                $('#smcg_description').on('input', function() {
                    if ($(this).val().length > 200) {
                        $(this).val($(this).val().substring(0, 200));
                    }
                });

                $('#smcg_generate_button').on('click', function() {
                    var $button = $(this);
                    var title = $('#smcg_title').val().trim();
                    var description = $('#smcg_description').val().trim();
                    
                    $('#smcg_error').text('');
                    $('#smcg_image_preview').html('');
                    
                    if (!title) {
                        $('#smcg_error').text('<?php echo esc_js(__('Title is required.', 'social-media-card-generator')); ?>');
                        return;
                    }
                    if (<?php echo intval($post_id); ?> === 0) {
                        $('#smcg_error').text('<?php echo esc_js(__('Please save the post as a draft first.', 'social-media-card-generator')); ?>');
                        return;
                    }

                    $('#smcg_loader').show();
                    $button.prop('disabled', true);

                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: {
                            action: 'smcg_generate_image',
                            nonce: '<?php echo esc_js($ajax_nonce); ?>',
                            post_id: <?php echo intval($post_id); ?>,
                            title: title, description: description
                        },
                        timeout: 30000,
                        success: function(response) {
                            if (response.success) {
                                var previewHtml = '<strong><?php echo esc_js(__('Preview:', 'social-media-card-generator')); ?></strong><br>' +
                                                  '<img src="' + response.data.image_url + '" alt="<?php echo esc_js(__('Social Card Preview', 'social-media-card-generator')); ?>">';
                                $('#smcg_image_preview').html(previewHtml);
                            } else {
                                $('#smcg_error').text(response.data.message || '<?php echo esc_js(__('An error occurred.', 'social-media-card-generator')); ?>');
                            }
                        },
                        error: function(xhr, status, error) {
                            var errorMessage = '<?php echo esc_js(__('An error occurred.', 'social-media-card-generator')); ?>';
                            if (status === 'timeout') errorMessage = '<?php echo esc_js(__('Request timed out. The server may be busy.', 'social-media-card-generator')); ?>';
                            $('#smcg_error').text(errorMessage);
                        },
                        complete: function() {
                            $('#smcg_loader').hide();
                            $button.prop('disabled', false);
                        }
                    });
                });
            }
        });
        </script>
        <?php
    }
}

// Instantiate the plugin class.
new SocialMediaCardGeneratorPlugin();