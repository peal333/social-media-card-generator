<?php
/**
 * Plugin Name:       Social Media Card Generator
 * Description:       Allows users to easily create custom social media cards for posts directly within the WordPress Post Creation/Edit page.
 * Version:           1.4.1
 * Author:            Panupan Sriautharawong
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-media-card-generator
 */

namespace peal333\socialmediacardgenerator;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Get the plugin version dynamically from the plugin header.
$plugin_data = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
define('SOCIALMCG_VERSION', !empty($plugin_data['Version']) ? $plugin_data['Version'] : '1.4.1');

// Define constants
define('SOCIALMCG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SOCIALMCG_FONT_PATH', SOCIALMCG_PLUGIN_DIR . 'fonts/OpenSans-Regular.ttf');
define('SOCIALMCG_MAX_IMAGE_SIZE', 5 * 1024 * 1024); // 5MB limit

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

        // Scripts and Styles using the correct hook
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handler for image generation
        add_action('wp_ajax_socialmcg_generate_image', [$this, 'generate_image_callback']);
        
        // Admin notices
        add_action('admin_notices', [$this, 'check_requirements']);
    }

    /**
     * Plugin activation hook.
     */
    public function activate() {
        // Create fonts directory if it doesn't exist
        $fonts_dir = SOCIALMCG_PLUGIN_DIR . 'fonts';
        if (!file_exists($fonts_dir)) {
            wp_mkdir_p($fonts_dir);
        }
        
        // Set default options
        add_option('socialmcg_title_font_size', 82);
        add_option('socialmcg_description_font_size', 42);
        add_option('socialmcg_title_y_position', 50);
        add_option('socialmcg_description_y_position', 88);
        add_option('socialmcg_output_format', 'jpeg');
        add_option('socialmcg_jpeg_quality', 70);
    }

    /**
     * Plugin deactivation hook.
     */
    public function deactivate() {
        // Clean up any temporary files or transients if needed
        delete_transient('socialmcg_font_check');
    }

    /**
     * Check system requirements and display admin notices.
     */
    public function check_requirements() {
        $notices = [];
        
        if (!extension_loaded('gd')) {
            $notices[] = __('The GD library is required for image generation but is not installed on your server.', 'social-media-card-generator');
        }
        
        $font_check = get_transient('socialmcg_font_check');
        if (false === $font_check) {
            $font_check = file_exists(SOCIALMCG_FONT_PATH) ? 'exists' : 'missing';
            set_transient('socialmcg_font_check', $font_check, HOUR_IN_SECONDS);
        }
        
        if ($font_check === 'missing') {
            /* translators: 1: The font file name (e.g., OpenSans-Regular.ttf). 2: The directory path (e.g., wp-content/plugins/social-media-card-generator/fonts/). */
            $notices[] = sprintf( __('For best results, please upload %1$s to the %2$s directory. A system font will be used as fallback.', 'social-media-card-generator'),
                '<code>OpenSans-Regular.ttf</code>',
                '<code>wp-content/plugins/social-media-card-generator/fonts/</code>'
            );
        }
        
        foreach ($notices as $notice) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s:</strong> %s</p></div>',
                esc_html__('Social Media Card Generator', 'social-media-card-generator'),
                wp_kses($notice, ['code' => []])
            );
        }
    }

    /**
     * Get available font path with fallback.
     */
    private function get_font_path() {
        if (file_exists(SOCIALMCG_FONT_PATH)) {
            return SOCIALMCG_FONT_PATH;
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
                settings_fields('socialmcg_settings_group');
                do_settings_sections('social-media-card-generator');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        // Main Settings Section
        add_settings_section('socialmcg_settings_section', __('Layout & Style Settings', 'social-media-card-generator'), null, 'social-media-card-generator');

        register_setting('socialmcg_settings_group', 'socialmcg_template_image_id', ['type' => 'number', 'sanitize_callback' => 'absint']);
        add_settings_field('socialmcg_template_image_id', __('Template Image', 'social-media-card-generator'), [$this, 'render_template_image_field'], 'social-media-card-generator', 'socialmcg_settings_section');
        
        register_setting('socialmcg_settings_group', 'socialmcg_title_font_size', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_font_size'], 'default' => 82]);
        add_settings_field('socialmcg_title_font_size', __('Title Font Size (px)', 'social-media-card-generator'), [$this, 'render_font_size_field'], 'social-media-card-generator', 'socialmcg_settings_section', ['name' => 'socialmcg_title_font_size', 'default' => 82]);
        
        register_setting('socialmcg_settings_group', 'socialmcg_title_y_position', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_position_percentage'], 'default' => 50]);
        add_settings_field('socialmcg_title_y_position', __('Title Y-Position (%)', 'social-media-card-generator'), [$this, 'render_position_field'], 'social-media-card-generator', 'socialmcg_settings_section', ['name' => 'socialmcg_title_y_position', 'default' => 50, 'desc' => __('Vertical center of the text block. 50% is perfectly centered.', 'social-media-card-generator')]);

        register_setting('socialmcg_settings_group', 'socialmcg_description_font_size', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_font_size'], 'default' => 42]);
        add_settings_field('socialmcg_description_font_size', __('Description Font Size (px)', 'social-media-card-generator'), [$this, 'render_font_size_field'], 'social-media-card-generator', 'socialmcg_settings_section', ['name' => 'socialmcg_description_font_size', 'default' => 42]);

        register_setting('socialmcg_settings_group', 'socialmcg_description_y_position', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_position_percentage'], 'default' => 88]);
        add_settings_field('socialmcg_description_y_position', __('Description Y-Position (%)', 'social-media-card-generator'), [$this, 'render_position_field'], 'social-media-card-generator', 'socialmcg_settings_section', ['name' => 'socialmcg_description_y_position', 'default' => 88, 'desc' => __('Vertical center of the text block.', 'social-media-card-generator')]);

        // Output Settings Section
        add_settings_section('socialmcg_output_settings_section', __('Output Settings', 'social-media-card-generator'), null, 'social-media-card-generator');

        register_setting('socialmcg_settings_group', 'socialmcg_output_format', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_output_format'], 'default' => 'jpeg']);
        add_settings_field('socialmcg_output_format', __('Output Format', 'social-media-card-generator'), [$this, 'render_output_format_field'], 'social-media-card-generator', 'socialmcg_output_settings_section');

        register_setting('socialmcg_settings_group', 'socialmcg_jpeg_quality', ['type' => 'number', 'sanitize_callback' => [$this, 'sanitize_jpeg_quality'], 'default' => 70]);
        add_settings_field('socialmcg_jpeg_quality', __('JPEG Quality', 'social-media-card-generator'), [$this, 'render_jpeg_quality_field'], 'social-media-card-generator', 'socialmcg_output_settings_section');
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
        $current_format = get_option('socialmcg_output_format', 'jpeg');
        ?>
        <select id="socialmcg_output_format" name="socialmcg_output_format">
            <option value="jpeg" <?php selected($current_format, 'jpeg'); ?>>JPEG</option>
            <option value="png" <?php selected($current_format, 'png'); ?>>PNG</option>
        </select>
        <p class="description"><?php esc_html_e('Choose JPEG for smaller file sizes or PNG for higher quality.', 'social-media-card-generator'); ?></p>
        <?php
    }

    public function render_jpeg_quality_field() {
        $quality = get_option('socialmcg_jpeg_quality', 70);
        printf(
            '<input type="number" id="socialmcg_jpeg_quality" name="socialmcg_jpeg_quality" value="%s" min="1" max="100" />',
            esc_attr($quality)
        );
        printf('<p class="description">%s</p>', esc_html__('For JPEG format only. A value between 1 (low quality) and 100 (best quality).', 'social-media-card-generator'));
    }

    public function render_template_image_field() {
        $image_id = get_option('socialmcg_template_image_id');
        echo '<input type="hidden" id="socialmcg_template_image_id" name="socialmcg_template_image_id" value="' . esc_attr($image_id) . '">';
        echo '<button type="button" class="button" id="socialmcg_upload_image_button">' . esc_html__('Select Image', 'social-media-card-generator') . '</button>';
        echo '<div id="socialmcg_template_image_preview" style="margin-top: 15px;">';
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
        add_meta_box('socialmcg_meta_box', __('Social Media Card Generator', 'social-media-card-generator'), [$this, 'render_meta_box'], 'post', 'side', 'high');
    }

    public function render_meta_box($post) {
        ?>
        <div class="socialmcg-meta-box-wrapper">
            <p>
                <label for="socialmcg_title"><strong><?php esc_html_e('Title', 'social-media-card-generator'); ?></strong></label>
                <textarea id="socialmcg_title" name="socialmcg_title" style="width:100%;" rows="3" required><?php echo esc_textarea(get_the_title($post->ID)); ?></textarea>
            </p>
            <p>
                <label for="socialmcg_description"><strong><?php esc_html_e('Description (Optional)', 'social-media-card-generator'); ?></strong></label>
                <textarea id="socialmcg_description" name="socialmcg_description" style="width:100%;" rows="3" maxlength="200"></textarea>
            </p>
            <button type="button" id="socialmcg_generate_button" class="button button-primary is-full-width"><?php esc_html_e('Generate Card', 'social-media-card-generator'); ?></button>
            <div id="socialmcg_loader" style="display:none; text-align: center; margin: 10px 0;"><span class="spinner is-active"></span> <?php esc_html_e('Generating...', 'social-media-card-generator'); ?></div>
            <div id="socialmcg_error" style="color:red; margin-top:10px; font-weight: bold;"></div>
            <div id="socialmcg_image_preview" style="margin-top:15px; max-width: 100%; height: auto;"><p class="description"><?php esc_html_e('Preview of the generated card will appear here.', 'social-media-card-generator'); ?></p></div>
        </div>
        <?php
    }

    //======================================================================
    // 3. IMAGE GENERATION
    //======================================================================
    
    public function generate_image_callback() {
        if (!check_ajax_referer('socialmcg_generate_image_nonce', 'nonce', false)) wp_send_json_error(['message' => __('Security check failed.', 'social-media-card-generator')]);
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => __('Insufficient permissions.', 'social-media-card-generator')]);
        if (!extension_loaded('gd')) wp_send_json_error(['message' => __('GD library is not available on this server.', 'social-media-card-generator')]);
        
        $font_path = $this->get_font_path();
        if (!$font_path) wp_send_json_error(['message' => __('No suitable font found on the server.', 'social-media-card-generator')]);
        
        // Use the WordPress function to safely increase memory for image processing.
        wp_raise_memory_limit('image');
        
        $title = isset($_POST['title']) ? sanitize_textarea_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        
        if (empty($title) || $post_id === 0) wp_send_json_error(['message' => __('Missing required data (title or post ID).', 'social-media-card-generator')]);
        
        $template_id = get_option('socialmcg_template_image_id');
        if (!$template_id) wp_send_json_error(['message' => __('No template image selected. Please set one in the plugin settings.', 'social-media-card-generator')]);
        
        $template_path = get_attached_file($template_id);
        if (!$template_path || !file_exists($template_path)) wp_send_json_error(['message' => __('Template image file cannot be found.', 'social-media-card-generator')]);
        if (filesize($template_path) > SOCIALMCG_MAX_IMAGE_SIZE) wp_send_json_error(['message' => __('Template image is too large. Maximum size is 5MB.', 'social-media-card-generator')]);
        
        $result = $this->create_social_card_image($template_path, $title, $description, $post_id, $font_path);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
        
        wp_send_json_success($result);
    }

    private function create_social_card_image($template_path, $title, $description, $post_id, $font_path) {
        $image_info = @getimagesize($template_path);
        if (!$image_info) return new \WP_Error('invalid_image', __('Invalid or corrupted template image.', 'social-media-card-generator'));
        
        $image = null;
        switch ($image_info['mime']) {
            case 'image/jpeg': $image = @imagecreatefromjpeg($template_path); break;
            case 'image/png': $image = @imagecreatefrompng($template_path); break;
            case 'image/gif': $image = @imagecreatefromgif($template_path); break;
            case 'image/webp': if (function_exists('imagecreatefromwebp')) $image = @imagecreatefromwebp($template_path); break;
        }
        
        if (!$image) return new \WP_Error('image_creation_failed', __('Failed to create image resource.', 'social-media-card-generator'));
        
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 100 || $height < 100) {
            imagedestroy($image);
            return new \WP_Error('image_too_small', __('Template image is too small. Minimum size is 100x100 pixels.', 'social-media-card-generator'));
        }
        
        $title_font_size = absint(get_option('socialmcg_title_font_size', 82));
        $desc_font_size = absint(get_option('socialmcg_description_font_size', 42));
        $title_y_pos = absint(get_option('socialmcg_title_y_position', 50));
        $desc_y_pos = absint(get_option('socialmcg_description_y_position', 88));

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
    
        if (empty($lines)) {
            return new \WP_Error('text_wrap_failed', __('Failed to process text. It might be empty or contain invalid characters.', 'social-media-card-generator'));
        }
    
        // Calculate an accurate line height from the font metrics.
        $line_bbox = imagettfbbox($font_size, 0, $font_path, "Sg"); // "Sg" has ascenders and descenders
        if (!$line_bbox) {
            return new \WP_Error('font_error', __('Could not get font bounding box.', 'social-media-card-generator'));
        }
        $line_height = $line_bbox[1] - $line_bbox[7]; // lower-left-y - upper-left-y
    
        // Calculate the total height of the multi-line text block
        $total_text_height = count($lines) * $line_height;
    
        // Calculate the desired Y coordinate for the absolute center of the text block
        $block_center_y = ($height * $y_position_percent) / 100;
    
        // Calculate the Y coordinate for the top of the text block
        $block_top_y = $block_center_y - ($total_text_height / 2);
    
        // Calculate the baseline Y for the first line of text.
        // imagettftext's y-coordinate is the baseline. $line_bbox[7] is the 'top' coordinate, which is typically negative.
        $start_y = $block_top_y - $line_bbox[7];
    
        foreach ($lines as $i => $line) {
            $bbox = imagettfbbox($font_size, 0, $font_path, $line);
            $text_width = $bbox[2] - $bbox[0];
            $x = ($width - $text_width) / 2;
            $y = $start_y + ($i * $line_height);
    
            // Optional: Don't draw text that is fully off-screen
            if ($y < 0 || $y > $height + $font_size) {
                continue;
            }
    
            // Draw shadow first, then text
            imagettftext($image, $font_size, 0, $x + 2, $y + 2, $shadow_color, $font_path, $line);
            imagettftext($image, $font_size, 0, $x, $y, $text_color, $font_path, $line);
        }
    
        return true;
    }

    private function wrap_text($text, $font_path, $font_size, $max_width) {
        // Normalize line endings to \n
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $user_lines = explode("\n", $text);
        $final_lines = [];
    
        foreach ($user_lines as $user_line) {
            $words = explode(' ', $user_line);
            $current_line = '';
    
            foreach ($words as $word) {
                $test_line = empty($current_line) ? $word : $current_line . ' ' . $word;
                $bbox = @imagettfbbox($font_size, 0, $font_path, $test_line);
    
                if (!$bbox) { // In case of font processing error
                    continue;
                }
    
                $test_width = $bbox[2] - $bbox[0];
    
                if ($test_width <= $max_width) {
                    $current_line = $test_line;
                } else {
                    if (!empty($current_line)) {
                        $final_lines[] = $current_line;
                    }
                    $current_line = $word;
                }
            }
    
            if (!empty($current_line)) {
                $final_lines[] = $current_line;
            }
        }
    
        return $final_lines;
    }

    private function save_generated_image($image, $title, $post_id) {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) return new \WP_Error('upload_dir_error', $upload_dir['error']);

        $format = get_option('socialmcg_output_format', 'jpeg');
        $quality = absint(get_option('socialmcg_jpeg_quality', 70));
        
        $extension = ($format === 'png') ? 'png' : 'jpg';
        $mime_type = ($format === 'png') ? 'image/png' : 'image/jpeg';
        
        // Sanitize the title for the filename, but also keep it brief
        $sane_title = sanitize_title(substr($title, 0, 50));
        $filename = sanitize_file_name($sane_title . '-social-card-' . time() . '.' . $extension);
        $filepath = $upload_dir['path'] . '/' . $filename;
        
        $saved_successfully = false;
        if ($format === 'png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $saved_successfully = imagepng($image, $filepath, 9);
        } else {
            $saved_successfully = imagejpeg($image, $filepath, $quality);
        }

        if (!$saved_successfully) return new \WP_Error('image_save_failed', __('Failed to save the generated image.', 'social-media-card-generator'));
        
        $attachment = [
            'guid'           => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => $mime_type,
            /* translators: %s: The title of the post. */
            'post_title'     => sprintf(__('Social Card: %s', 'social-media-card-generator'), $title),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        
        $attach_id = wp_insert_attachment($attachment, $filepath, $post_id);
        if (is_wp_error($attach_id)) {
            // Use the WordPress alternative to unlink().
            wp_delete_file($filepath);
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
    
    /**
     * Enqueue scripts and styles for the admin area.
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_admin_assets($hook) {
        $screen = get_current_screen();

        // Only load assets on the plugin's settings page and on the post edit screen.
        if ( ! (('post.php' === $hook || 'post-new.php' === $hook) && $screen->post_type === 'post') && 'settings_page_social-media-card-generator' !== $screen->id) {
            return;
        }
        
        // Enqueue WP Media library
        wp_enqueue_media();
        
        // Enqueue Styles
        $this->enqueue_admin_styles();

        // Enqueue Scripts
        $this->enqueue_admin_scripts($hook);
    }

    /**
     * Enqueue and add inline styles.
     */
    private function enqueue_admin_styles() {
        // We use a dummy handle because we are only adding inline styles.
        // The version number is included for consistency and best practice.
        wp_register_style('socialmcg-admin-styles', false, [], SOCIALMCG_VERSION);
        wp_enqueue_style('socialmcg-admin-styles');

        $custom_css = "
            #socialmcg_template_image_preview img { 
                margin-top: 10px; 
                border: 1px solid #ddd; 
                padding: 5px; 
                max-width: 100%; 
                height: auto; 
            }
            .socialmcg-meta-box-wrapper .button.is-full-width { 
                width: 100%; 
                margin-bottom: 10px; 
            }
            #socialmcg_image_preview img { 
                border: 1px solid #ddd; 
                max-width: 100%; 
                height: auto; 
                box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            }
            #socialmcg_loader .spinner { 
                float: none; 
                vertical-align: middle; 
                margin-right: 5px; 
            }
            .socialmcg-meta-box-wrapper textarea { 
                resize: vertical; 
                min-height: 60px; 
            }
            .form-table td p.description { 
                margin-top: 4px; 
            }
            /* Hides the JPEG quality field by default */
            .settings_page_social-media-card-generator .form-table tr.socialmcg-jpeg-quality-row.hidden { 
                display: none; 
            }
        ";
        wp_add_inline_style('socialmcg-admin-styles', $custom_css);
    }

    /**
     * Enqueue and add inline scripts.
     * @param string $hook The current admin page.
     */
    private function enqueue_admin_scripts($hook) {
        // We use a dummy handle here as well, with dependencies on jQuery.
        // The version number is included for consistency and best practice.
        wp_register_script('socialmcg-admin-scripts', false, ['jquery'], SOCIALMCG_VERSION, true);
        wp_enqueue_script('socialmcg-admin-scripts');
        
        $post_id = get_the_ID();
        
        // Use wp_localize_script to pass data to our script.
        // This is the recommended and safest way to pass PHP variables to JavaScript.
        wp_localize_script('socialmcg-admin-scripts', 'socialmcg_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('socialmcg_generate_image_nonce'),
            'post_id' => $post_id ?: 0,
            'is_settings_page' => 'settings_page_social-media-card-generator' === get_current_screen()->id,
            'is_post_edit_page' => 'post.php' === $hook || 'post-new.php' === $hook,
            'i18n' => [
                'selectTemplateImage' => __('Select a Template Image', 'social-media-card-generator'),
                'useThisImage'        => __('Use this image', 'social-media-card-generator'),
                'templatePreview'     => __('Template Preview', 'social-media-card-generator'),
                'titleRequired'       => __('Title is required.', 'social-media-card-generator'),
                'saveDraftFirst'      => __('Please save the post as a draft first.', 'social-media-card-generator'),
                'preview'             => __('Preview:', 'social-media-card-generator'),
                'socialCardPreview'   => __('Social Card Preview', 'social-media-card-generator'),
                'errorOccurred'       => __('An error occurred.', 'social-media-card-generator'),
                'requestTimedOut'     => __('Request timed out. The server may be busy.', 'social-media-card-generator'),
            ]
        ]);

        $inline_script = "
        jQuery(document).ready(function($) {
            // SCRIPT FOR SETTINGS PAGE
            if (socialmcg_params.is_settings_page) {
                // Media uploader script
                $('#socialmcg_upload_image_button').on('click', function(e) {
                    e.preventDefault();
                    var image_frame = wp.media({
                        title: socialmcg_params.i18n.selectTemplateImage,
                        button: { text: socialmcg_params.i18n.useThisImage },
                        multiple: false, library: { type: 'image' }
                    });
                    image_frame.on('select', function() {
                        var attachment = image_frame.state().get('selection').first().toJSON();
                        $('#socialmcg_template_image_id').val(attachment.id);
                        var imageUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                        $('#socialmcg_template_image_preview').html('<img src=\"' + imageUrl + '\" alt=\"' + socialmcg_params.i18n.templatePreview + '\">');
                    });
                    image_frame.open();
                });

                // Conditional display for JPEG quality
                var \$outputFormat = $('#socialmcg_output_format');
                var \$jpegQualityRow = $('#socialmcg_jpeg_quality').closest('tr').addClass('socialmcg-jpeg-quality-row');

                function toggleQualityField() {
                    if (\$outputFormat.val() === 'jpeg') {
                        \$jpegQualityRow.removeClass('hidden');
                    } else {
                        \$jpegQualityRow.addClass('hidden');
                    }
                }
                toggleQualityField(); // Run on page load
                \$outputFormat.on('change', toggleQualityField);
            }

            // SCRIPT FOR POST META BOX
            if (socialmcg_params.is_post_edit_page) {
                // Sync post title with card title (one-way)
                $('#title').on('input', function() { 
                    var cardTitle = $('#socialmcg_title');
                    // Only update if user hasn't typed a multi-line title
                    if (cardTitle.val().indexOf('\\n') === -1) {
                        cardTitle.val($(this).val()); 
                    }
                });

                // Character limit for description
                $('#socialmcg_description').on('input', function() {
                    if ($(this).val().length > 200) {
                        $(this).val($(this).val().substring(0, 200));
                    }
                });

                // AJAX image generation
                $('#socialmcg_generate_button').on('click', function() {
                    var \$button = $(this);
                    var title = $('#socialmcg_title').val().trim();
                    var description = $('#socialmcg_description').val().trim();
                    
                    $('#socialmcg_error').text('');
                    $('#socialmcg_image_preview').html('');
                    
                    if (!title) {
                        $('#socialmcg_error').text(socialmcg_params.i18n.titleRequired);
                        return;
                    }
                    if (socialmcg_params.post_id === 0) {
                        $('#socialmcg_error').text(socialmcg_params.i18n.saveDraftFirst);
                        return;
                    }

                    $('#socialmcg_loader').show();
                    \$button.prop('disabled', true);

                    $.ajax({
                        url: socialmcg_params.ajax_url, type: 'POST',
                        data: {
                            action: 'socialmcg_generate_image',
                            nonce: socialmcg_params.nonce,
                            post_id: socialmcg_params.post_id,
                            title: title, description: description
                        },
                        timeout: 30000, // 30 seconds
                        success: function(response) {
                            if (response.success) {
                                // Add a timestamp to the image URL to bust browser cache
                                var imageUrl = response.data.image_url + '?t=' + new Date().getTime();
                                var previewHtml = '<strong>' + socialmcg_params.i18n.preview + '</strong><br>' +
                                                  '<img src=\"' + imageUrl + '\" alt=\"' + socialmcg_params.i18n.socialCardPreview + '\">';
                                $('#socialmcg_image_preview').html(previewHtml);
                            } else {
                                $('#socialmcg_error').text(response.data.message || socialmcg_params.i18n.errorOccurred);
                            }
                        },
                        error: function(xhr, status, error) {
                            var errorMessage = socialmcg_params.i18n.errorOccurred;
                            if (status === 'timeout') {
                                errorMessage = socialmcg_params.i18n.requestTimedOut;
                            }
                            $('#socialmcg_error').text(errorMessage);
                        },
                        complete: function() {
                            $('#socialmcg_loader').hide();
                            \$button.prop('disabled', false);
                        }
                    });
                });
            }
        });
        ";
        wp_add_inline_script('socialmcg-admin-scripts', $inline_script);
    }
}

// Instantiate the plugin class using its fully qualified name.
new \peal333\socialmediacardgenerator\SocialMediaCardGeneratorPlugin();