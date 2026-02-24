<?php
/**
 * File: src/pro/includes/modules/videoconf/providers/class-google-meet-provider.php
 * Passive provider for Google Meet — reads links stored by Google Calendar Sync.
 *
 * Google Meet links are generated automatically when Google Calendar Sync creates
 * events with conferenceData. This provider simply reads the stored meta.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Videoconf
 */

namespace ClientSyncPro\Modules\Videoconf\Providers;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) exit;

class Google_Meet_Provider implements Videoconf_Provider_Interface {

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'google_meet';
    }

    /**
     * Reads the Google Meet link already stored by Google_Sync_Service::create_google_event().
     * This is a passive provider — the actual Meet link is created by the Google Calendar API
     * when conferenceData is included in the event payload.
     *
     * {@inheritdoc}
     */
    public function create_meeting( int $appointment_id ): ?array {
        $link = get_post_meta( $appointment_id, Constants::META_MEETING_LINK, true );
        if ( empty( $link ) ) {
            return null;
        }

        return [
            'link'       => $link,
            'provider'   => $this->get_id(),
            'meeting_id' => get_post_meta( $appointment_id, Constants::META_MEETING_ID, true ) ?: '',
            'password'   => '',
        ];
    }

    /**
     * Google Sync handles the event update; the Meet link persists automatically.
     * {@inheritdoc}
     */
    public function update_meeting( int $appointment_id ): ?array {
        return $this->create_meeting( $appointment_id );
    }

    /**
     * Clean up meeting meta. The Google Calendar event (and its Meet link) is
     * already deleted by Google_Sync_Service::delete_google_event().
     * {@inheritdoc}
     */
    public function delete_meeting( int $appointment_id ): bool {
        delete_post_meta( $appointment_id, Constants::META_MEETING_LINK );
        delete_post_meta( $appointment_id, Constants::META_MEETING_PROVIDER );
        delete_post_meta( $appointment_id, Constants::META_MEETING_ID );
        return true;
    }
}
