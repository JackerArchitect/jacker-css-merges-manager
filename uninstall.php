<?php
/**
 * Uninstall handler for Jacker CSS Merges Manager.
 *
 * Runs only when the plugin is deleted from the WordPress admin.
 * Removes all plugin options and the merged CSS cache directory.
 *
 * @package JCSSMM
 */

// WordPress sets this constant when running uninstall.php.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'jcssmm_settings' );
delete_option( 'jcssmm_page_map' );

// Remove per-site options on multisite.
if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'jcssmm_settings' );
		delete_option( 'jcssmm_page_map' );
		restore_current_blog();
	}
}

// Remove the merged CSS cache directory.
$upload = wp_upload_dir();
if ( empty( $upload['error'] ) ) {
	$cache_dir = trailingslashit( $upload['basedir'] ) . 'jcssmm';

	if ( is_dir( $cache_dir ) ) {
		// Delete every file inside, then the directory itself.
		$files = glob( trailingslashit( $cache_dir ) . '*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}
		@rmdir( $cache_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}