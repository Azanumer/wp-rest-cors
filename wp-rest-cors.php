<?php
/**
 * Plugin Name: REST CORS
 * Plugin URI: https://github.com/Azanumer/wp-rest-cors
 * Description: Control Cross-Origin Resource Sharing for the WordPress REST API — an allow-list of origins, optional credentials, and proper OPTIONS preflight handling, so your decoupled front end or mobile app can call the API safely.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-rest-cors
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WRC_VERSION', '1.0.0' );
define( 'WRC_OPTION', 'wrc_settings' );

/**
 * Default settings.
 *
 * @return array
 */
function wrc_default_settings() {
	return array(
		'allowed_origins'   => '',
		'allow_credentials' => 0,
		'allowed_methods'   => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
		'allowed_headers'   => 'Authorization, Content-Type, X-WP-Nonce',
		'max_age'           => 86400,
	);
}

/**
 * Get merged settings.
 *
 * @return array
 */
function wrc_get_settings() {
	$saved = get_option( WRC_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( wrc_default_settings(), $saved );
}

/**
 * Allowed origins, one per line in settings.
 *
 * @return string[]
 */
function wrc_allowed_origins() {
	$settings = wrc_get_settings();
	$origins  = array();
	foreach ( preg_split( "/\r\n|\r|\n/", (string) $settings['allowed_origins'] ) as $line ) {
		$line = strtolower( trim( $line ) );
		if ( '' !== $line ) {
			$origins[] = $line;
		}
	}
	return apply_filters( 'wrc_allowed_origins', $origins );
}

/**
 * Check whether an Origin is allowed.
 *
 * Supports exact matches, the catch-all `*`, and subdomain wildcards
 * such as `https://*.example.com`.
 *
 * @param string $origin Origin header value.
 * @return bool
 */
function wrc_origin_allowed( $origin ) {
	$origin = strtolower( trim( $origin ) );
	if ( '' === $origin ) {
		return false;
	}
	foreach ( wrc_allowed_origins() as $allowed ) {
		if ( '*' === $allowed ) {
			return true;
		}
		if ( $allowed === $origin ) {
			return true;
		}
		// Subdomain wildcard: https://*.example.com (or a bare *.example.com).
		$allowed_host = wp_parse_url( $allowed, PHP_URL_HOST );
		if ( null === $allowed_host ) {
			$allowed_host = $allowed; // No scheme given.
		}
		if ( 0 === strpos( $allowed_host, '*.' ) ) {
			$suffix = substr( $allowed_host, 1 ); // ".example.com" (keeps the dot).
			$parsed = wp_parse_url( $origin );
			$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
			if ( '' !== $host && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Build the CORS headers for an allowed origin.
 *
 * @param string $origin Origin header value.
 * @return array Header name => value.
 */
function wrc_cors_headers( $origin ) {
	$settings = wrc_get_settings();
	$headers  = array(
		'Access-Control-Allow-Origin' => $origin,
		'Vary'                        => 'Origin',
	);
	// Credentials are only meaningful for a specific origin, never for *.
	if ( ! empty( $settings['allow_credentials'] ) && '*' !== $origin && ! in_array( '*', wrc_allowed_origins(), true ) ) {
		$headers['Access-Control-Allow-Credentials'] = 'true';
	}
	return $headers;
}

/**
 * Answer OPTIONS preflight requests before WordPress routes them.
 *
 * Returning a response from rest_pre_dispatch short-circuits dispatch,
 * which is exactly what a preflight needs: headers, no body, no route.
 *
 * @param mixed             $result  Short-circuit result (null to continue).
 * @param WP_REST_Server    $server  REST server.
 * @param WP_REST_Request   $request Request object.
 * @return mixed
 */
function wrc_handle_preflight( $result, $server, $request ) {
	if ( null !== $result ) {
		return $result;
	}
	if ( 'OPTIONS' !== $request->get_method() ) {
		return $result;
	}
	$origin = $request->get_header( 'origin' );
	if ( '' === $origin || ! wrc_origin_allowed( $origin ) ) {
		return $result;
	}
	$settings = wrc_get_settings();
	$response = new WP_REST_Response( null, 200 );
	foreach ( wrc_cors_headers( $origin ) as $name => $value ) {
		$response->header( $name, $value );
	}
	$response->header( 'Access-Control-Allow-Methods', $settings['allowed_methods'] );
	$response->header( 'Access-Control-Allow-Headers', $settings['allowed_headers'] );
	$response->header( 'Access-Control-Max-Age', (string) max( 0, (int) $settings['max_age'] ) );
	return $response;
}
add_filter( 'rest_pre_dispatch', 'wrc_handle_preflight', 10, 3 );

/**
 * Add CORS headers to every real REST response for an allowed origin.
 *
 * @param WP_REST_Response $response Response object.
 * @param WP_REST_Server   $server   REST server.
 * @param WP_REST_Request  $request  Request object.
 * @return WP_REST_Response
 */
function wrc_add_cors_headers( $response, $server, $request ) {
	$origin = $request->get_header( 'origin' );
	if ( '' === $origin || ! wrc_origin_allowed( $origin ) ) {
		return $response;
	}
	if ( ! $response instanceof WP_REST_Response ) {
		$response = new WP_REST_Response( $response );
	}
	// If the allow-list is a catch-all, echo the wildcard instead of the
	// requesting origin so browsers treat it as public.
	$echo_origin = in_array( '*', wrc_allowed_origins(), true ) ? '*' : $origin;
	foreach ( wrc_cors_headers( $echo_origin ) as $name => $value ) {
		$response->header( $name, $value );
	}
	return $response;
}
add_filter( 'rest_post_dispatch', 'wrc_add_cors_headers', 10, 3 );

/**
 * Settings page under Settings → REST CORS.
 */
function wrc_register_settings_page() {
	add_options_page(
		__( 'REST CORS', 'wp-rest-cors' ),
		__( 'REST CORS', 'wp-rest-cors' ),
		'manage_options',
		'wrc-settings',
		'wrc_render_settings_page'
	);
}
add_action( 'admin_menu', 'wrc_register_settings_page' );

/**
 * Render the settings page.
 */
function wrc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-rest-cors' ) );
	}

	if ( isset( $_POST['wrc_save'] ) ) {
		check_admin_referer( 'wrc_save_settings' );
		$input = wp_unslash( $_POST );
		update_option(
			WRC_OPTION,
			array(
				'allowed_origins'   => isset( $input['allowed_origins'] ) ? sanitize_textarea_field( $input['allowed_origins'] ) : '',
				'allow_credentials' => isset( $input['allow_credentials'] ) ? 1 : 0,
				'allowed_methods'   => isset( $input['allowed_methods'] ) ? sanitize_text_field( $input['allowed_methods'] ) : 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
				'allowed_headers'   => isset( $input['allowed_headers'] ) ? sanitize_text_field( $input['allowed_headers'] ) : 'Authorization, Content-Type, X-WP-Nonce',
				'max_age'           => max( 0, (int) ( $input['max_age'] ?? 86400 ) ),
			)
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wp-rest-cors' ) . '</p></div>';
	}

	$settings = wrc_get_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'REST CORS', 'wp-rest-cors' ); ?></h1>
		<p><?php esc_html_e( 'CORS headers are only added to REST API responses, and only for origins on your allow-list. Requests without an Origin header, or from an origin not listed, get no CORS headers at all.', 'wp-rest-cors' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'wrc_save_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wrc-origins"><?php esc_html_e( 'Allowed origins', 'wp-rest-cors' ); ?></label></th>
					<td>
						<textarea id="wrc-origins" name="allowed_origins" rows="6" cols="50"><?php echo esc_textarea( $settings['allowed_origins'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One origin per line, e.g. https://app.example.com. Use * to allow any origin (public API only — credentials are then disabled), or https://*.example.com to allow all subdomains.', 'wp-rest-cors' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allow credentials', 'wp-rest-cors' ); ?></th>
					<td><label><input type="checkbox" name="allow_credentials" value="1" <?php checked( ! empty( $settings['allow_credentials'] ) ); ?> /> <?php esc_html_e( 'Send Access-Control-Allow-Credentials (cookies / Authorization) — only works with specific origins, never with *.', 'wp-rest-cors' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="wrc-methods"><?php esc_html_e( 'Allowed methods', 'wp-rest-cors' ); ?></label></th>
					<td><input type="text" id="wrc-methods" name="allowed_methods" value="<?php echo esc_attr( $settings['allowed_methods'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wrc-headers"><?php esc_html_e( 'Allowed headers', 'wp-rest-cors' ); ?></label></th>
					<td><input type="text" id="wrc-headers" name="allowed_headers" value="<?php echo esc_attr( $settings['allowed_headers'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wrc-maxage"><?php esc_html_e( 'Preflight cache (seconds)', 'wp-rest-cors' ); ?></label></th>
					<td><input type="number" id="wrc-maxage" name="max_age" value="<?php echo (int) $settings['max_age']; ?>" min="0" /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'wp-rest-cors' ), 'primary', 'wrc_save' ); ?>
		</form>
	</div>
	<?php
}
