<?php
/**
 * File: src/pro/includes/modules/videoconf/providers/interface-videoconf-provider.php
 * Contract for video conferencing providers (Zoom, Google Meet, etc.).
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Videoconf
 */

namespace ClientSyncPro\Modules\Videoconf\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

interface Videoconf_Provider_Interface {

    /**
     * Returns the provider identifier (e.g., 'zoom', 'google_meet').
     */
    public function get_id(): string;

    /**
     * Create a video meeting for the given appointment.
     *
     * @param int $appointment_id The WordPress appointment post ID.
     * @return array|null Array with keys: link, provider, meeting_id, password (optional). Null on failure.
     */
    public function create_meeting( int $appointment_id ): ?array;

    /**
     * Update an existing video meeting when the appointment is rescheduled.
     *
     * @param int $appointment_id The WordPress appointment post ID.
     * @return array|null Updated meeting data, or null on failure.
     */
    public function update_meeting( int $appointment_id ): ?array;

    /**
     * Delete a video meeting when the appointment is cancelled/trashed.
     *
     * @param int $appointment_id The WordPress appointment post ID.
     * @return bool True on success.
     */
    public function delete_meeting( int $appointment_id ): bool;
}
