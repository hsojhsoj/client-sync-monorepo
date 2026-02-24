<?php
/**
 * File: src/pro/includes/modules/videoconf/class-videoconf-notification-bridge.php
 * Injects the {meeting_link} placeholder into the notification system.
 *
 * Hooks into the `clisyc_notification_placeholder_data` filter to add the meeting
 * link URL to all appointment-related email/SMS notification templates.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Videoconf
 */

namespace ClientSyncPro\Modules\Videoconf;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) exit;

class Videoconf_Notification_Bridge {

    /**
     * Appointment-related notification event types that may include a meeting link.
     */
    private const APPOINTMENT_EVENTS = [
        'new_appointment_client',
        'new_appointment_admin',
        'appointment_updated_admin',
        'appointment_cancelled_admin',
        'appointment_cancelled_by_client',
        'appointment_reminder',
        'payment_successful_client',
        'payment_successful_admin',
        'waitlist_promoted',
    ];

    public function __construct() {
        add_filter( 'clisyc_notification_placeholder_data', [ $this, 'inject_meeting_link_placeholder' ], 10, 4 );
    }

    /**
     * Add the {meeting_link} placeholder to notification data.
     *
     * @param array  $data       The placeholder data array.
     * @param string $event_type The notification event type.
     * @param int    $object_id  The appointment/object ID.
     * @param array  $extra_data Additional data passed to the notification.
     * @return array Modified placeholder data with {meeting_link}.
     */
    public function inject_meeting_link_placeholder( array $data, string $event_type, int $object_id, array $extra_data ): array {
        if ( ! in_array( $event_type, self::APPOINTMENT_EVENTS, true ) ) {
            $data['{meeting_link}'] = '';
            return $data;
        }

        $meeting_link = get_post_meta( $object_id, Constants::META_MEETING_LINK, true );
        $data['{meeting_link}'] = ! empty( $meeting_link ) ? esc_url( $meeting_link ) : '';

        return $data;
    }
}
