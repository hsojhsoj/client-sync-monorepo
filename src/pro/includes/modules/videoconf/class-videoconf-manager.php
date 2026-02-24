<?php
/**
 * File: src/pro/includes/modules/videoconf/class-videoconf-manager.php
 * Manages hooks for creating/updating/deleting video meetings on appointment events.
 *
 * Hooks at priority 25 (after Google Sync at 10) so that for Google Meet,
 * the calendar event — and its Meet link — already exist by the time this runs.
 *
 * For Zoom, this manager actively calls the Zoom API to create meetings.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Videoconf
 */

namespace ClientSyncPro\Modules\Videoconf;

use DependentMedia\ClientSync\Constants;
use ClientSyncPro\Modules\Videoconf\Providers\Videoconf_Provider_Interface;

if ( ! defined( 'ABSPATH' ) ) exit;

class Videoconf_Manager {

    public function __construct() {
        // Create meeting for new appointments (fires after Google Sync at priority 10).
        add_action( 'clisyc_appointment_confirmed', [ $this, 'handle_new_appointment' ], 25 );
        add_action( 'clisyc_appointment_created', [ $this, 'handle_new_appointment' ], 25 );

        // Update meeting when appointment is modified.
        add_action( 'save_post_clisyc_appointment', [ $this, 'handle_updated_appointment' ], 30, 2 );

        // Delete meeting when appointment is trashed.
        add_action( 'wp_trash_post', [ $this, 'handle_deleted_appointment' ], 25 );
    }

    /**
     * Handle a newly created appointment — create a video meeting if applicable.
     *
     * @param int $appointment_id The appointment post ID.
     */
    public function handle_new_appointment( int $appointment_id ): void {
        // Avoid duplicate processing if both hooks fire for the same appointment.
        if ( get_post_meta( $appointment_id, Constants::META_MEETING_LINK, true ) ) {
            return;
        }

        if ( ! $this->is_video_enabled_for_appointment( $appointment_id ) ) {
            return;
        }

        $provider = Videoconf_Provider_Factory::get_provider();
        if ( ! $provider ) {
            return;
        }

        // Google Meet provider is passive — the link was already stored by Google Sync.
        // For Google Meet, we just verify the link exists (it should by now, at priority 25).
        if ( $provider->get_id() === 'google_meet' ) {
            return; // Link already stored by Google_Sync_Service::create_google_event().
        }

        // For Zoom (and future active providers), create the meeting now.
        $meeting_data = $provider->create_meeting( $appointment_id );
        if ( $meeting_data && ! empty( $meeting_data['link'] ) ) {
            update_post_meta( $appointment_id, Constants::META_MEETING_LINK, $meeting_data['link'] );
            update_post_meta( $appointment_id, Constants::META_MEETING_PROVIDER, $meeting_data['provider'] );
            update_post_meta( $appointment_id, Constants::META_MEETING_ID, $meeting_data['meeting_id'] );
            if ( ! empty( $meeting_data['password'] ) ) {
                update_post_meta( $appointment_id, Constants::META_MEETING_PASSWORD, $meeting_data['password'] );
            }
        }
    }

    /**
     * Handle an updated appointment — update the video meeting if it exists.
     *
     * @param int      $post_id The post ID.
     * @param \WP_Post $post    The post object.
     */
    public function handle_updated_appointment( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;

        $syncable_statuses = [ 'publish', Constants::STATUS_CONFIRMED ];
        if ( ! in_array( $post->post_status, $syncable_statuses, true ) ) return;

        $meeting_id = get_post_meta( $post_id, Constants::META_MEETING_ID, true );
        if ( ! $meeting_id ) {
            return; // No existing meeting to update.
        }

        $provider = Videoconf_Provider_Factory::get_provider();
        if ( ! $provider || $provider->get_id() === 'google_meet' ) {
            return; // Google Meet updates are handled by Google Sync.
        }

        $provider->update_meeting( $post_id );
    }

    /**
     * Handle a trashed appointment — delete the video meeting.
     *
     * @param int $post_id The post ID.
     */
    public function handle_deleted_appointment( int $post_id ): void {
        if ( Constants::POST_TYPE_APPOINTMENT !== get_post_type( $post_id ) ) {
            return;
        }

        $meeting_id = get_post_meta( $post_id, Constants::META_MEETING_ID, true );
        if ( ! $meeting_id ) {
            return;
        }

        $provider = Videoconf_Provider_Factory::get_provider();
        if ( ! $provider || $provider->get_id() === 'google_meet' ) {
            return; // Google Meet deletion is handled by Google Sync.
        }

        $provider->delete_meeting( $post_id );
    }

    /**
     * Check if the appointment's service has video conferencing enabled.
     *
     * @param int $appointment_id The appointment post ID.
     * @return bool
     */
    private function is_video_enabled_for_appointment( int $appointment_id ): bool {
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
}
