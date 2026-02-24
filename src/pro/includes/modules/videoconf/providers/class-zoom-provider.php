<?php
/**
 * File: src/pro/includes/modules/videoconf/providers/class-zoom-provider.php
 * Active provider for Zoom — creates, updates, and deletes Zoom meetings via REST API.
 *
 * Uses Zoom's Server-to-Server OAuth for authentication (no per-user OAuth needed).
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Videoconf
 */

namespace ClientSyncPro\Modules\Videoconf\Providers;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;
use ClientSyncPro\Modules\Videoconf\Services\Zoom_Auth_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

class Zoom_Provider implements Videoconf_Provider_Interface {

    const API_BASE = 'https://api.zoom.us/v2';

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'zoom';
    }

    /**
     * Creates a Zoom meeting for the appointment.
     * {@inheritdoc}
     */
    public function create_meeting( int $appointment_id ): ?array {
        $token = Zoom_Auth_Service::get_access_token();
        if ( ! $token ) {
            return null;
        }

        $time_slot_utc = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
        $duration      = (int) get_post_meta( $appointment_id, Constants::META_APPOINTMENT_DURATION, true );
        if ( ! $time_slot_utc || ! $duration ) {
            return null;
        }

        $topic = $this->build_meeting_topic( $appointment_id );

        $start_dt = new \DateTime( $time_slot_utc, new \DateTimeZone( 'UTC' ) );

        $response = wp_remote_post( self::API_BASE . '/users/me/meetings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'topic'      => $topic,
                'type'       => 2, // Scheduled meeting
                'start_time' => $start_dt->format( 'Y-m-d\TH:i:s\Z' ),
                'duration'   => $duration,
                'timezone'   => 'UTC',
                'settings'   => [
                    'join_before_host'  => true,
                    'waiting_room'      => false,
                    'auto_recording'    => 'none',
                    'meeting_authentication' => false,
                ],
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'Create meeting failed: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 201 || empty( $body['join_url'] ) ) {
            $this->log_error( 'Create meeting response error (HTTP ' . $code . '): ' . wp_json_encode( $body ) );
            return null;
        }

        return [
            'link'       => $body['join_url'],
            'provider'   => $this->get_id(),
            'meeting_id' => (string) $body['id'],
            'password'   => $body['password'] ?? '',
        ];
    }

    /**
     * Updates an existing Zoom meeting's time and duration.
     * {@inheritdoc}
     */
    public function update_meeting( int $appointment_id ): ?array {
        $meeting_id = get_post_meta( $appointment_id, Constants::META_MEETING_ID, true );
        if ( ! $meeting_id ) {
            return null;
        }

        $token = Zoom_Auth_Service::get_access_token();
        if ( ! $token ) {
            return null;
        }

        $time_slot_utc = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
        $duration      = (int) get_post_meta( $appointment_id, Constants::META_APPOINTMENT_DURATION, true );
        if ( ! $time_slot_utc || ! $duration ) {
            return null;
        }

        $topic    = $this->build_meeting_topic( $appointment_id );
        $start_dt = new \DateTime( $time_slot_utc, new \DateTimeZone( 'UTC' ) );

        $response = wp_remote_request( self::API_BASE . '/meetings/' . $meeting_id, [
            'method'  => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'topic'      => $topic,
                'start_time' => $start_dt->format( 'Y-m-d\TH:i:s\Z' ),
                'duration'   => $duration,
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'Update meeting failed: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 204 ) {
            $this->log_error( 'Update meeting response error (HTTP ' . $code . ')' );
            return null;
        }

        // Zoom PATCH returns 204 No Content on success; existing link and ID remain valid.
        return [
            'link'       => get_post_meta( $appointment_id, Constants::META_MEETING_LINK, true ),
            'provider'   => $this->get_id(),
            'meeting_id' => $meeting_id,
            'password'   => get_post_meta( $appointment_id, Constants::META_MEETING_PASSWORD, true ) ?: '',
        ];
    }

    /**
     * Deletes a Zoom meeting.
     * {@inheritdoc}
     */
    public function delete_meeting( int $appointment_id ): bool {
        $meeting_id = get_post_meta( $appointment_id, Constants::META_MEETING_ID, true );
        if ( ! $meeting_id ) {
            return true; // Nothing to delete.
        }

        $token = Zoom_Auth_Service::get_access_token();
        if ( ! $token ) {
            return false;
        }

        $response = wp_remote_request( self::API_BASE . '/meetings/' . $meeting_id, [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'Delete meeting failed: ' . $response->get_error_message() );
            return false;
        }

        // Clean up post meta regardless of API response.
        delete_post_meta( $appointment_id, Constants::META_MEETING_LINK );
        delete_post_meta( $appointment_id, Constants::META_MEETING_PROVIDER );
        delete_post_meta( $appointment_id, Constants::META_MEETING_ID );
        delete_post_meta( $appointment_id, Constants::META_MEETING_PASSWORD );

        return true;
    }

    /**
     * Build the meeting topic, respecting HIPAA anonymization.
     *
     * @param int $appointment_id The appointment post ID.
     * @return string The meeting topic/title.
     */
    private function build_meeting_topic( int $appointment_id ): string {
        // Check HIPAA mode.
        $hipaa_mode = function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' )
            && \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode()
            && get_option( Constants::OPTION_ANONYMIZE_EXTERNAL_SYNC, true );

        if ( $hipaa_mode ) {
            return sprintf(
                /* translators: %d: appointment ID */
                __( 'Busy - Appt #%d', 'client-sync-pro' ),
                $appointment_id
            );
        }

        // Normal mode: include service title.
        $dimensions = get_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, true );
        $registry   = get_option( Constants::OPTION_DIMENSION_REGISTRY, [ 'dimensions' => [] ] );
        $title      = __( 'Appointment', 'client-sync-pro' );

        foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
            if ( ! empty( $settings['primary'] ) && ! empty( $dimensions[ $slug ] ) ) {
                $post_title = get_the_title( $dimensions[ $slug ] );
                if ( $post_title ) {
                    $title = $post_title;
                }
                break;
            }
        }

        $client_user = get_userdata( get_post_field( 'post_author', $appointment_id ) );
        if ( $client_user ) {
            $title .= ' - ' . $client_user->display_name;
        }

        return $title;
    }

    /**
     * Log an error message.
     *
     * @param string $message The error message.
     */
    private function log_error( string $message ): void {
        Debug_Logger::log( $message, 'Zoom' );
    }
}
