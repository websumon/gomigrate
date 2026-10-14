<?php
/**
 * Uninstall handler for GoMigrate.
 *
 * @package GoMigrate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$gomigrate_upload = wp_upload_dir();
$gomigrate_directory = trailingslashit( $gomigrate_upload['basedir'] ) . 'gomigrate';

if ( is_dir( $gomigrate_directory ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    global $wp_filesystem;

    if ( $wp_filesystem ) {
        $wp_filesystem->delete( $gomigrate_directory, true );
    }
}
