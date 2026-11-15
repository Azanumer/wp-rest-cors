<?php
/**
 * Uninstall handler for REST CORS.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wrc_settings' );
