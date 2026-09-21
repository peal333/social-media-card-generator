<?php
/**
 * Plugin Name:       Social Media Card Generator
 * Description:       Generate branded social media cards from the WordPress post editor and save them to the Media Library.
 * Version:           1.5.3
 * Requires at least: 5.0
 * Requires PHP:      7.0
 * Author:            Panupan Sriautharawong
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-media-card-generator
 */

namespace Peal333\SocialMediaCardGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOCIALMCG_VERSION', '1.5.3' );
define( 'SOCIALMCG_PLUGIN_FILE', __FILE__ );
define( 'SOCIALMCG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOCIALMCG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SOCIALMCG_DEFAULT_FONT', 'bundled:OpenSans-Regular.ttf' );
define( 'SOCIALMCG_MAX_IMAGE_SIZE', 5 * 1024 * 1024 );
define( 'SOCIALMCG_MAX_FONT_SIZE_BYTES', 5 * 1024 * 1024 );

require_once SOCIALMCG_PLUGIN_DIR . 'includes/class-socialmcg-plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

Plugin::instance();
