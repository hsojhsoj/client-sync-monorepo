<?php
/**
 * File: tests/unit/VideoconfNotificationBridgeTest.php
 * Unit tests for the Video Conferencing Notification Bridge.
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Constants;

class VideoconfNotificationBridgeTest extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();

        if ( ! class_exists( 'ClientSyncPro\Modules\Videoconf\Videoconf_Notification_Bridge' ) ) {
            $this->markTestSkipped( 'Client Sync Pro is not active. Skipping VideoconfNotificationBridge tests.' );
        }
    }

    /**
     * @test
     * The {meeting_link} placeholder should be populated when the appointment has a meeting link.
     */
    public function it_injects_meeting_link_placeholder() {
        // --- ARRANGE ---
        $appointment_id = $this->factory()->post->create([
            'post_type'   => 'clisyc_appointment',
            'post_status' => 'publish',
        ]);
        update_post_meta( $appointment_id, Constants::META_MEETING_LINK, 'https://meet.google.com/test-link' );

        $bridge = new \ClientSyncPro\Modules\Videoconf\Videoconf_Notification_Bridge();

        // --- ACT ---
        $data = $bridge->inject_meeting_link_placeholder(
            [],
            'new_appointment_client',
            $appointment_id,
            []
        );

        // --- ASSERT ---
        $this->assertArrayHasKey( '{meeting_link}', $data, 'The {meeting_link} key should exist in placeholder data.' );
        $this->assertStringContainsString(
            'meet.google.com/test-link',
            $data['{meeting_link}'],
            'The meeting link URL should be populated.'
        );
    }

    /**
     * @test
     * The {meeting_link} placeholder should be empty when no meeting link exists.
     */
    public function it_returns_empty_when_no_meeting() {
        // --- ARRANGE ---
        $appointment_id = $this->factory()->post->create([
            'post_type'   => 'clisyc_appointment',
            'post_status' => 'publish',
        ]);
        // No meeting link meta set.

        $bridge = new \ClientSyncPro\Modules\Videoconf\Videoconf_Notification_Bridge();

        // --- ACT ---
        $data = $bridge->inject_meeting_link_placeholder(
            [],
            'new_appointment_client',
            $appointment_id,
            []
        );

        // --- ASSERT ---
        $this->assertArrayHasKey( '{meeting_link}', $data );
        $this->assertEmpty( $data['{meeting_link}'], 'The meeting link should be empty when no meta exists.' );
    }

    /**
     * @test
     * For non-appointment events, {meeting_link} should be empty.
     */
    public function it_returns_empty_for_non_appointment_events() {
        $bridge = new \ClientSyncPro\Modules\Videoconf\Videoconf_Notification_Bridge();

        $data = $bridge->inject_meeting_link_placeholder(
            [],
            'new_client_registration_client',
            1,
            []
        );

        $this->assertArrayHasKey( '{meeting_link}', $data );
        $this->assertEmpty( $data['{meeting_link}'] );
    }
}
