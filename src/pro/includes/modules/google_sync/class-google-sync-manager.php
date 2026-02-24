<?php
/**
 * File: src/pro/includes/modules/google_sync/class-google-sync-manager.php -> client-sync-pro/includes/modules/google_sync/class-google-sync-manager.php
 * Manages the hooks and triggers for syncing appointments to Google Calendar.
 *
 * @package    ClientSyncPro
 */

namespace ClientSyncPro;

use ClientSyncPro\Services\Google_Sync_Service;
use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) exit;

class Google_Sync_Manager {

    const SYNC_IN_HOOK = 'clisyc_google_sync_from_calendar';
    private $sync_service;

    public function __construct() {
        $this->sync_service = new Google_Sync_Service();

        // Sync on new confirmed appointments (jQuery form, WooCommerce, Stripe)
        add_action( 'clisyc_appointment_confirmed', [ $this, 'handle_new_appointment' ] );

        // Sync on new appointments from React form and recurring series
        add_action( 'clisyc_appointment_created', [ $this, 'handle_new_appointment' ] );

        // Sync on updates (e.g., admin changing the time)
        add_action( 'save_post_clisyc_appointment', [ $this, 'handle_updated_appointment' ], 20, 2 );

        // Sync on deletion
        add_action( 'wp_trash_post', [ $this, 'handle_deleted_appointment' ] );

        // Cron job for inbound sync (Google → Client Sync)
        add_action( 'init', [ $this, 'schedule_sync_in_cron' ] );
        add_action( self::SYNC_IN_HOOK, [ $this, 'run_sync_from_google_cron' ] );
    }

    public function schedule_sync_in_cron() {
        if ( ! wp_next_scheduled( self::SYNC_IN_HOOK ) ) {
            wp_schedule_event( time(), 'fifteen_minutes', self::SYNC_IN_HOOK );
        }
    }

    public function run_sync_from_google_cron() {
        // Find all users who are linked to an employee and have a Google token
        $connected_users = get_users([
            'meta_key' => Google_Sync_Service::TOKEN_USER_META,
            'fields'   => 'ID',
        ]);

        foreach ( $connected_users as $user_id ) {
            $this->sync_service->sync_calendar_from_google( $user_id );
        }
    }

    public function handle_new_appointment( int $appointment_id ) {
        $this->sync_service->sync_appointment_to_google( $appointment_id );
    }

    public function handle_updated_appointment( int $post_id, \WP_Post $post ) {
        // Prevent action on autosaves, revisions, or non-syncable statuses
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;

        $syncable_statuses = [ 'publish', Constants::STATUS_CONFIRMED ];
        if ( ! in_array( $post->post_status, $syncable_statuses, true ) ) return;

        $this->sync_service->sync_appointment_to_google( $post_id );
    }

    public function handle_deleted_appointment( int $post_id ) {
        if ( 'clisyc_appointment' !== get_post_type( $post_id ) ) {
            return;
        }
        $this->sync_service->delete_google_event( $post_id );
    }
}
