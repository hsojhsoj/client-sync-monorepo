<?php
/**
 * File: src/pro/includes/modules/google_sync/services/class-google-sync-service.php
 * Service class for handling Google Calendar API interactions.
 *
 * Provides two-way sync between Client Sync appointments and Google Calendar.
 * Supports HIPAA-compliant anonymization of PHI when syncing to external services.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Services
 */

namespace ClientSyncPro\Services;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

class Google_Sync_Service {

    const CREDENTIALS_OPTION   = 'clisyc_google_api_credentials';
    const TOKEN_USER_META      = 'clisyc_google_sync_tokens';
    const GOOGLE_EVENT_ID_META = '_clisyc_google_event_id';
    const GOOGLE_SYNC_SOURCE_META = '_clisyc_google_sync_source';

    /**
     * @var \Google_Client|null Allows overriding the Google Client for testing.
     */
    private $google_client_override = null;

    private $calendar_service_override = null;

    /**
     * A testing hook to inject a mock client.
     * @param \Google_Client $client The mock Google_Client object.
     */
    public function set_google_client( \Google_Client $client ) {
        $this->google_client_override = $client;
    }

    public function set_calendar_service( \Google_Service_Calendar $service ) {
        $this->calendar_service_override = $service;
    }

    /**
     * Creates and configures a Google_Client object.
     * @return \Google_Client|null
     */
    private function get_client(): ?\Google_Client {
        if ( $this->google_client_override ) {
            return $this->google_client_override;
        }
        $credentials = get_option( self::CREDENTIALS_OPTION, [] );
        if ( empty( $credentials['client_id'] ) || empty( $credentials['client_secret'] ) ) {
            return null;
        }

        $client = new \Google_Client();
        $client->setApplicationName( 'Client Sync Pro' );
        $client->setClientId( $credentials['client_id'] );
        $client->setClientSecret( $credentials['client_secret'] );
        $client->setRedirectUri( admin_url( 'admin.php?page=clisyc-settings&tab=integrations&action=google_oauth_callback' ) );
        $client->setAccessType( 'offline' );
        $client->setApprovalPrompt( 'force' );
        $client->setScopes( [ \Google_Service_Calendar::CALENDAR, \Google_Service_Oauth2::USERINFO_EMAIL ] );
        return $client;
    }

    /**
     * Gets an authenticated Google_Client for a specific user, refreshing the token if needed.
     * @param int $user_id The WordPress user ID of the employee.
     * @return \Google_Client|null An authenticated client or null on failure.
     */
    private function get_client_for_user( int $user_id ): ?\Google_Client {
        $client = $this->get_client();
        if ( ! $client ) {
            return null;
        }

        $token_data = get_user_meta( $user_id, self::TOKEN_USER_META, true );
        if ( empty( $token_data['access_token'] ) ) {
            return null;
        }

        $client->setAccessToken( $token_data );

        if ( $client->isAccessTokenExpired() ) {
            if ( empty( $token_data['refresh_token'] ) ) {
                // Cannot refresh without a refresh token.
                self::disconnect( $user_id );
                return null;
            }

            try {
                $client->fetchAccessTokenWithRefreshToken( $token_data['refresh_token'] );
                $new_token = $client->getAccessToken();

                if ( ! empty( $new_token['error'] ) ) {
                    Debug_Logger::log( 'Token refresh failed for user ' . $user_id . ': ' . ( $new_token['error_description'] ?? $new_token['error'] ), 'GoogleSync' );
                    self::disconnect( $user_id );
                    return null;
                }

                // The new token may not include a refresh token, so merge them.
                $new_token_data = array_merge( $token_data, $new_token );
                $new_token_data['expires_at'] = time() + ( $new_token_data['expires_in'] ?? 3600 );

                update_user_meta( $user_id, self::TOKEN_USER_META, $new_token_data );
            } catch ( \Exception $e ) {
                Debug_Logger::log( 'Token refresh exception for user ' . $user_id . ': ' . $e->getMessage(), 'GoogleSync' );
                self::disconnect( $user_id );
                return null;
            }
        }

        return $client;
    }

    public function get_auth_url( int $employee_wp_user_id ): ?string {
        $client = $this->get_client();
        if ( ! $client ) {
            return null;
        }
        $client->setState( base64_encode( wp_json_encode( [ 'user_id' => $employee_wp_user_id ] ) ) );
        return $client->createAuthUrl();
    }

    public function handle_oauth_callback( string $code, string $state ): \WP_Error|array {
        $client = $this->get_client();
        if ( ! $client ) {
            return new \WP_Error( 'no_credentials', 'Google API credentials are not configured.' );
        }

        $decoded_state = json_decode( base64_decode( $state ), true );
        $user_id = $decoded_state['user_id'] ?? 0;
        if ( ! $user_id ) {
            return new \WP_Error( 'invalid_state', 'The authentication state was invalid or missing.' );
        }

        $token = $client->fetchAccessTokenWithAuthCode( $code );
        if ( isset( $token['error'] ) ) {
            return new \WP_Error( 'token_error', 'Failed to retrieve access token: ' . $token['error_description'] );
        }

        $oauth2 = new \Google_Service_Oauth2( $client );
        $userInfo = $oauth2->userinfo->get();

        $token_data = [
            'access_token'  => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_at'    => time() + $token['expires_in'],
            'email'         => $userInfo->getEmail(),
        ];

        update_user_meta( $user_id, self::TOKEN_USER_META, $token_data );
        return $token_data;
    }

    /**
     * Main sync function. Decides whether to create or update an event.
     * @param int $appointment_id The ID of the WordPress appointment.
     */
    public function sync_appointment_to_google( int $appointment_id ) {
        $google_event_id = get_post_meta( $appointment_id, self::GOOGLE_EVENT_ID_META, true );

        if ( ! empty( $google_event_id ) ) {
            $this->update_google_event( $appointment_id, $google_event_id );
        } else {
            $this->create_google_event( $appointment_id );
        }
    }

    /**
     * Deletes an event from Google Calendar.
     * @param int $appointment_id The ID of the WordPress appointment.
     */
    public function delete_google_event( int $appointment_id ) {
        $google_event_id = get_post_meta( $appointment_id, self::GOOGLE_EVENT_ID_META, true );
        if ( ! $google_event_id ) {
            return; // Nothing to delete
        }

        $user_id = $this->get_employee_user_id_from_appointment( $appointment_id );
        if ( ! $user_id ) return;

        $client = $this->get_client_for_user( $user_id );
        if ( ! $client ) return;

        try {
            $calendar_service = $this->calendar_service_override ?: new \Google_Service_Calendar( $client );
            $this->api_call_with_retry( fn() => $calendar_service->events->delete( 'primary', $google_event_id ) );
            delete_post_meta( $appointment_id, self::GOOGLE_EVENT_ID_META );
        } catch ( \Exception $e ) {
            Debug_Logger::log( 'Failed to delete event: ' . $e->getMessage(), 'GoogleSync' );
        }
    }

    private function create_google_event( int $appointment_id ) {
        $user_id = $this->get_employee_user_id_from_appointment( $appointment_id );
        if ( ! $user_id ) {
            return;
        }

        $client = $this->get_client_for_user( $user_id );
        if ( ! $client ) {
            return;
        }

        $event_data = $this->build_event_data( $appointment_id );
        if ( ! $event_data ) {
            return;
        }

        try {
            // Use the override if it exists, otherwise create a real one.
            $calendar_service = $this->calendar_service_override ?: new \Google_Service_Calendar( $client );

            $event = new \Google_Service_Calendar_Event( $event_data );

            // Include conferenceDataVersion when Google Meet is requested.
            $insert_params = [ 'sendUpdates' => 'all' ];
            if ( ! empty( $event_data['conferenceData'] ) ) {
                $insert_params['conferenceDataVersion'] = 1;
            }
            $created_event = $this->api_call_with_retry( fn() => $calendar_service->events->insert( 'primary', $event, $insert_params ) );

            if ( $created_event->getId() ) {
                update_post_meta( $appointment_id, self::GOOGLE_EVENT_ID_META, $created_event->getId() );
                update_post_meta( $appointment_id, self::GOOGLE_SYNC_SOURCE_META, 'clientsync' );

                // Extract Google Meet link from the created event.
                if ( method_exists( $created_event, 'getHangoutLink' ) ) {
                    $hangout_link = $created_event->getHangoutLink();
                    if ( $hangout_link ) {
                        update_post_meta( $appointment_id, Constants::META_MEETING_LINK, $hangout_link );
                        update_post_meta( $appointment_id, Constants::META_MEETING_PROVIDER, 'google_meet' );
                        update_post_meta( $appointment_id, Constants::META_MEETING_ID, $created_event->getId() );
                    }
                }
            }
        } catch ( \Exception $e ) {
            Debug_Logger::log( 'Failed to create event: ' . $e->getMessage(), 'GoogleSync' );
        }
    }

    private function update_google_event( int $appointment_id, string $google_event_id ) {
        $user_id = $this->get_employee_user_id_from_appointment( $appointment_id );
        if ( ! $user_id ) return;

        $client = $this->get_client_for_user( $user_id );
        if ( ! $client ) return;

        $event_data = $this->build_event_data( $appointment_id );
        if ( ! $event_data ) return;

        try {
            $calendar_service = $this->calendar_service_override ?: new \Google_Service_Calendar( $client );
            $event = new \Google_Service_Calendar_Event( $event_data );

            $update_params = [ 'sendUpdates' => 'all' ];
            if ( ! empty( $event_data['conferenceData'] ) ) {
                $update_params['conferenceDataVersion'] = 1;
            }
            $this->api_call_with_retry( fn() => $calendar_service->events->update( 'primary', $google_event_id, $event, $update_params ) );
        } catch ( \Exception $e ) {
            Debug_Logger::log( 'Failed to update event: ' . $e->getMessage(), 'GoogleSync' );
        }
    }

    /**
     * Constructs the data array for a Google Calendar event from a WP appointment.
     *
     * HIPAA COMPLIANCE: When HIPAA mode is active, this method anonymizes all PHI
     * before sending to Google Calendar. Client names, emails, and notes are replaced
     * with generic placeholders to prevent PHI exposure to third-party services.
     *
     * @param int $appointment_id The WordPress appointment post ID.
     * @return array|null The event data array, or null if required data is missing.
     */
    private function build_event_data( int $appointment_id ): ?array {
        $time_slot_utc = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
        $duration = (int) get_post_meta( $appointment_id, Constants::META_APPOINTMENT_DURATION, true );
        if ( ! $time_slot_utc || ! $duration ) return null;

        $start_dt = new \DateTime( $time_slot_utc, new \DateTimeZone('UTC') );
        $end_dt = (clone $start_dt)->modify("+{$duration} minutes");

        // =========================================================================
        // HIPAA COMPLIANCE: Check if anonymization is required
        // =========================================================================
        $hipaa_mode = $this->is_hipaa_anonymization_enabled();

        $client_user = get_userdata( get_post_field('post_author', $appointment_id) );
        $primary_item_title = $this->get_primary_dimension_title_from_appointment( $appointment_id );

        if ( $hipaa_mode ) {
            // =========================================================================
            // HIPAA MODE: Anonymize all identifying information
            // =========================================================================
            $summary = sprintf(
                /* translators: %d: appointment ID */
                __( 'Busy - Appt #%d', 'client-sync-pro' ),
                $appointment_id
            );

            $description = sprintf(
                /* translators: %d: appointment ID */
                __( "Appointment #%d\n\nDetails available in WordPress.\n\nManage: ", 'client-sync-pro' ),
                $appointment_id
            ) . get_edit_post_link( $appointment_id, 'raw' );

            // No attendees in HIPAA mode - don't expose client email to Google
            $attendees = [];
        } else {
            // =========================================================================
            // NORMAL MODE: Full details included
            // =========================================================================
            $summary = $primary_item_title;
            if ( $client_user ) {
                $summary .= ' - ' . $client_user->display_name;
            }

            $description = "Appointment Details:\n";
            $description .= "Service: " . $primary_item_title . "\n";
            if ( $client_user ) {
                $description .= "Client: " . $client_user->display_name . "\n";
                $description .= "Email: " . $client_user->user_email . "\n";
            }
            $description .= "\nManage in WordPress: " . get_edit_post_link( $appointment_id, 'raw' );

            $attendees = [];
            if ( $client_user && is_email($client_user->user_email) ) {
                $attendees[] = ['email' => $client_user->user_email];
            }
        }

        $event_data = [
            'summary' => $summary,
            'description' => $description,
            'start' => [
                'dateTime' => $start_dt->format( \DateTime::RFC3339 ),
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $end_dt->format( \DateTime::RFC3339 ),
                'timeZone' => 'UTC',
            ],
            'attendees' => $attendees,
        ];

        // Add Google Meet conference data if the provider is Google Meet and the service has video enabled.
        if ( get_option( Constants::OPTION_VIDEO_PROVIDER ) === 'google_meet' && $this->should_attach_video( $appointment_id ) ) {
            $event_data['conferenceData'] = [
                'createRequest' => [
                    'requestId'             => 'clisyc-' . $appointment_id . '-' . time(),
                    'conferenceSolutionKey' => [ 'type' => 'hangoutsMeet' ],
                ],
            ];
        }

        return $event_data;
    }

    /**
     * Check if HIPAA anonymization should be applied for external sync.
     *
     * This checks both HIPAA mode and the anonymize_external_sync option,
     * allowing users to disable anonymization even in HIPAA mode if needed.
     *
     * @return bool True if PHI should be anonymized for external services.
     */
    private function is_hipaa_anonymization_enabled(): bool {
        // Check if the HIPAA helper function exists
        if ( ! function_exists( '\DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode' ) ) {
            return false;
        }

        // Check if HIPAA mode is active
        if ( ! \DependentMedia\ClientSync\Services\clisyc_is_hipaa_mode() ) {
            return false;
        }

        // Check if external sync anonymization is enabled (default: true when HIPAA mode is on)
        $anonymize_external = get_option( Constants::OPTION_ANONYMIZE_EXTERNAL_SYNC, true );

        return (bool) $anonymize_external;
    }

    /**
     * Check if the service assigned to an appointment has video conferencing enabled.
     *
     * @param int $appointment_id The appointment post ID.
     * @return bool True if video conferencing is enabled for the service.
     */
    private function should_attach_video( int $appointment_id ): bool {
        $dimensions = get_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, true );
        if ( ! is_array( $dimensions ) ) {
            return false;
        }
        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [ 'dimensions' => [] ] );
        foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
            if ( ! empty( $settings['primary'] ) && ! empty( $dimensions[ $slug ] ) ) {
                return (bool) get_post_meta( $dimensions[ $slug ], Constants::META_VIDEO_CONF_ENABLED, true );
            }
        }
        return false;
    }

    /**
     * Resolve the WordPress user ID of the employee assigned to an appointment.
     *
     * Looks through the appointment's dimensions to find one that has `enable_sync`
     * set in the dimension registry, then reads the `_clisyc_employee_user_id` meta
     * from that dimension post.
     *
     * @param int $appointment_id The appointment post ID.
     * @return int|null The WordPress user ID, or null if not found.
     */
    private function get_employee_user_id_from_appointment( int $appointment_id ): ?int {
        $dimensions = get_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, true );
        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );

        if ( ! is_array($dimensions) ) {
            return null;
        }

        // Find the first dimension that is sync-enabled and present in this appointment
        $employee_post_id = null;
        foreach ( $dimensions as $slug => $post_id ) {
            if ( ! empty( $registry['dimensions'][ $slug ]['enable_sync'] ) ) {
                $employee_post_id = $post_id;
                break; // Found a syncable item
            }
        }

        if ( ! $employee_post_id ) {
            return null;
        }

        return (int) get_post_meta( $employee_post_id, Constants::META_EMPLOYEE_USER_ID, true );
    }

    /**
     * Get the title of the primary dimension item from an appointment.
     *
     * @param int $appointment_id The appointment post ID.
     * @return string The dimension title, or 'Appointment' as fallback.
     */
    private function get_primary_dimension_title_from_appointment( int $appointment_id ): string {
        // *** FIX ***: Was reading 'clisyc_slot_dimensions' (missing underscore prefix).
        $dimensions = get_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, true );
        if ( ! is_array($dimensions) ) return 'Appointment';

        $registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
        $primary_dim_slug = null;
        foreach($registry['dimensions'] ?? [] as $slug => $settings) {
            if (!empty($settings['primary'])) {
                $primary_dim_slug = $slug;
                break;
            }
        }

        if ( $primary_dim_slug && !empty($dimensions[$primary_dim_slug]) ) {
            return get_the_title($dimensions[$primary_dim_slug]) ?: 'Appointment';
        }

        return 'Appointment';
    }

    /**
     * Execute a Google API call with retry and exponential backoff.
     *
     * @param callable $fn        The API call to execute.
     * @param int      $max_tries Maximum number of attempts.
     * @return mixed The result of the callable.
     * @throws \Exception Re-throws the last exception if all retries fail.
     */
    private function api_call_with_retry( callable $fn, int $max_tries = 3 ) {
        $last_exception = null;
        for ( $attempt = 1; $attempt <= $max_tries; $attempt++ ) {
            try {
                return $fn();
            } catch ( \Google\Service\Exception $e ) {
                $last_exception = $e;
                $code = $e->getCode();
                // Only retry on rate limit (429) or server errors (5xx).
                if ( $code !== 429 && $code < 500 ) {
                    throw $e;
                }
                if ( $attempt < $max_tries ) {
                    sleep( pow( 2, $attempt - 1 ) ); // 1s, 2s backoff.
                }
            } catch ( \Exception $e ) {
                $last_exception = $e;
                if ( $attempt < $max_tries ) {
                    sleep( pow( 2, $attempt - 1 ) );
                }
            }
        }
        throw $last_exception;
    }

    public static function disconnect( int $employee_wp_user_id ) {
        delete_user_meta( $employee_wp_user_id, self::TOKEN_USER_META );
    }

    /**
     * Fetches events from Google Calendar and blocks off corresponding time in Client Sync.
     *
     * This is the inbound sync direction (Google → Client Sync). It queries the
     * employee's primary Google Calendar for the next 14 days and blocks any
     * overlapping time slots to prevent double-bookings.
     *
     * @param int $user_id The WordPress user ID of the employee to sync.
     * @return array Status array with counts of processed events.
     */
    public function sync_calendar_from_google( int $user_id ): array {
        // Prevent concurrent syncs for the same user via transient lock.
        $lock_key = 'clisyc_gsync_lock_' . $user_id;
        if ( get_transient( $lock_key ) ) {
            return [ 'status' => 'skipped', 'message' => 'Sync already in progress for this user.' ];
        }
        set_transient( $lock_key, true, 5 * MINUTE_IN_SECONDS );

        $client = $this->get_client_for_user( $user_id );
        if ( ! $client ) {
            delete_transient( $lock_key );
            return [ 'status' => 'error', 'message' => 'Not connected to Google.' ];
        }

        $personnel_dim_slug = get_option( Constants::OPTION_PERSONNEL_DIM_SLUG, '' );
        if ( ! $personnel_dim_slug ) {
            delete_transient( $lock_key );
            return [ 'status' => 'error', 'message' => 'No Personnel Dimension is configured in settings.' ];
        }
        $employee_post_id = get_user_meta( $user_id, 'clisyc_employee_post_id', true );

        if ( ! $employee_post_id ) {
            delete_transient( $lock_key );
            return [ 'status' => 'error', 'message' => 'User is not linked to an employee resource.' ];
        }

        $calendar_service = new \Google_Service_Calendar( $client );
        $stats = [ 'processed' => 0, 'blocked' => 0, 'skipped' => 0 ];

        try {
            $opt_params = [
                'maxResults'   => 250,
                'orderBy'      => 'startTime',
                'singleEvents' => true,
                // Use gmdate() instead of date() to ensure a consistent UTC ISO8601 string
                'timeMin'      => gmdate( 'c' ),
                'timeMax'      => gmdate( 'c', strtotime( '+14 days' ) ), // Sync 2 weeks ahead
            ];

            $db_manager = new \DependentMedia\ClientSync\Core\Database_Manager();
            $admin_url  = get_admin_url();

            // Paginate through all results.
            do {
                $results = $this->api_call_with_retry( fn() => $calendar_service->events->listEvents( 'primary', $opt_params ) );
                $events = $results->getItems();

                foreach ( $events as $event ) {
                    $stats['processed']++;

                    // Skip all-day events (no specific dateTime)
                    if ( empty( $event->start->dateTime ) ) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Skip events originally created by Client Sync (to prevent sync loops).
                    $event_description = $event->getDescription() ?? '';
                    if ( str_contains( $event_description, 'Manage in WordPress:' ) || str_contains( $event_description, $admin_url ) ) {
                        $stats['skipped']++;
                        continue;
                    }

                    $start_utc_str = ( new \DateTime( $event->start->dateTime ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
                    $end_utc_str   = ( new \DateTime( $event->end->dateTime ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

                    $dimensions_to_block = [
                        $personnel_dim_slug => $employee_post_id,
                    ];

                    $blocked_count = $db_manager->block_overlapping_slots( $start_utc_str, $end_utc_str, $dimensions_to_block );

                    if ( $blocked_count > 0 ) {
                        $stats['blocked'] += $blocked_count;
                    }
                }

                // Get next page token if there are more results.
                $page_token = $results->getNextPageToken();
                if ( $page_token ) {
                    $opt_params['pageToken'] = $page_token;
                }
            } while ( $page_token );

        } catch ( \Exception $e ) {
            delete_transient( $lock_key );
            return [ 'status' => 'error', 'message' => 'API Error: ' . $e->getMessage() ];
        }

        delete_transient( $lock_key );
        return [ 'status' => 'success', 'message' => sprintf( 'Sync complete. Processed %d events, blocked %d overlapping slots.', $stats['processed'], $stats['blocked'] ) ] + $stats;
    }
}
