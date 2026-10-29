<?php
/**
 * Plugin Name:       Bulk Find Replace
 * Description:       Upload a CSV of find/replace pairs and run them across the database, with a dry-run preview. Handles serialized data safely, like wp search-replace.
 * Version:           1.3.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            BrainBytes Creative
 * License:           GPL-2.0-or-later
 * Text Domain:       bulk-find-replace
 *
 * @package Bulk_Find_Replace
 */

defined( 'ABSPATH' ) || exit;

define( 'BFR_VERSION', '1.3.0' );
define( 'BFR_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-bfr-replacer.php';
require_once __DIR__ . '/includes/class-bfr-job.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-bfr-admin.php';
	BFR_Admin::init();
}

register_uninstall_hook( __FILE__, 'bfr_uninstall' );

/**
 * Remove stored jobs.
 */
function bfr_uninstall() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'bfr_job_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
