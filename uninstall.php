<?php
/**
 * Uninstall script for Etsy WooCommerce AI Importer.
 *
 * This file runs when the plugin is deleted from WordPress.
 * It cleans up all plugin data from the database.
 *
 * @package Etsy_WooCommerce_AI_Importer
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete plugin options.
delete_option( 'etsy_importer_hf_api_token' );
delete_option( 'etsy_importer_use_ai' );

// Delete any transients we may have created.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_etsy_import_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_etsy_import_%'" );

// Clean up any pending Action Scheduler tasks.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'etsy_import_product_image' );
}

// Delete the temp import directory if it exists.
$upload_dir = wp_upload_dir();
$temp_dir   = $upload_dir['basedir'] . '/etsy-imports';
if ( is_dir( $temp_dir ) ) {
    // Remove all files in the directory.
    $files = glob( $temp_dir . '/*' );
    if ( $files ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                wp_delete_file( $file );
            }
        }
    }
    // Remove the directory.
    rmdir( $temp_dir );
}
