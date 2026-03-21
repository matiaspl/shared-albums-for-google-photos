<?php
/**
 * Plugin Name: YAPA Google Photo shared albums
 * Plugin URI: https://github.com/JanZeman/shared-albums-for-google-photos
 * Author URI: https://github.com/JanZeman
 * Description: Display publicly shared Google Photos albums with a modern Swiper-based gallery viewer (YAPA). Not affiliated with or endorsed by Google LLC.
 * Version: 1.0.13
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Author: YAPA
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: janzeman-shared-albums-for-google-photos
 * Domain Path: /languages
 *
 * WordPress.org and existing sites load this file path. Core implementation:
 * yapa-google-photo-shared-albums.php
 *
 * @package YAGA_Shared_Albums
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YAGA_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/yapa-google-photo-shared-albums.php';
