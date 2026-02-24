<?php
/**
 * File: src/pro/includes/modules/videoconf/services/class-zoom-auth-service.php
 * Manages Zoom Server-to-Server OAuth token acquisition and caching.
 *
 * Uses the account_credentials grant type (Zoom's recommended approach
 * since JWT apps were deprecated in 2023).
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Videoconf
 */

namespace ClientSyncPro\Modules\Videoconf\Services;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

class Zoom_Auth_Service {

    const TOKEN_ENDPOINT   = 'https://zoom.us/oauth/token';
    const TRANSIENT_KEY    = 'clisyc_zoom_access_token';

    /**
     * Get a valid Zoom access token, refreshing from the OAuth endpoint if expired.
     *
     * @return string|null The access token, or null on failure.
     */
    public static function get_access_token(): ?string {
        // 1. Check for cached token.
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( $cached ) {
            return $cached;
        }

        // 2. Fetch credentials from settings.
        $creds = get_option( Constants::OPTION_ZOOM_API_CREDENTIALS, [] );
        if ( empty( $creds['account_id'] ) || empty( $creds['client_id'] ) || empty( $creds['client_secret'] ) ) {
            return null;
        }

        // 3. Request new token via Server-to-Server OAuth (account_credentials grant).
        $response = wp_remote_post( self::TOKEN_ENDPOINT, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['client_id'] . ':' . $creds['client_secret'] ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'account_credentials',
                'account_id' => $creds['account_id'],
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            Debug_Logger::log( 'Token request failed: ' . $response->get_error_message(), 'ZoomAuth' );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            Debug_Logger::log( 'Token response error (HTTP ' . $code . '): ' . wp_json_encode( $body ), 'ZoomAuth' );
            return null;
        }

        // 4. Cache for slightly less than the expires_in value.
        $expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
        set_transient( self::TRANSIENT_KEY, $body['access_token'], max( 60, $expires_in - 60 ) );

        return $body['access_token'];
    }
}
