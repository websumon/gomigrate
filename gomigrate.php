<?php
/**
 * Plugin Name: GoMigrate – Transfer All Posts, Pages & Custom Post Types
 * Description: Transfer posts, pages, custom post types, media, users, taxonomies, comments and selected plugin data between WordPress sites.
 * Version: 1.0.4
 * Requires at least: 6.2
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: DevThrow
 * Author URI: https://devthrow.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gomigrate
 *
 * @package GoMigrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GOMIGRATE_VERSION', '1.0.4' );
define( 'GOMIGRATE_URL', plugin_dir_url( __FILE__ ) );
define( 'GOMIGRATE_PATH', plugin_dir_path( __FILE__ ) );

require_once GOMIGRATE_PATH . 'includes/class-gomigrate.php';

GoMigrate::instance();
