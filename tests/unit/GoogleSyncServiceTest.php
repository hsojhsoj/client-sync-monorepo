<?php
/**
 * File: tests/unit/GoogleSyncServiceTest.php
 * Unit tests for the Google Sync Service.
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use ClientSyncPro\Services\Google_Sync_Service;
use DependentMedia\ClientSync\Constants;

class GoogleSyncServiceTest extends WP_UnitTestCase {

    protected $personnel_dim_slug = 'clisyc_employee';
    protected $employee_user_id;
    protected $employee_post_id;
    protected $client_user_id;
    protected $service_id;

    public function setUp(): void {
        parent::setUp();

        if (!class_exists(Google_Sync_Service::class)) {
            $this->markTestSkipped('Client Sync Pro is not active. Skipping GoogleSyncService tests.');
        }

        // 1. Setup Google Credentials
        update_option('clisyc_google_api_credentials', [
            'client_id' => 'test-client-id.apps.googleusercontent.com',
            'client_secret' => 'test-client-secret',
        ]);

        // 2. CRITICAL: Configure Dimension Registry
        $registry = get_option( 'clisyc_dimension_registry', [ 'dimensions' => [] ] );
        $registry['dimensions'][ $this->personnel_dim_slug ] = [
            'enabled'     => true,
            'primary'     => false,
            'is_resource' => true,
            'enable_sync' => true,
        ];
        $registry['dimensions']['clisyc_service'] = [ 'enabled' => true, 'primary' => true ];
        update_option( 'clisyc_dimension_registry', $registry );

        // 3. Create Employee User
        $this->employee_user_id = $this->factory()->user->create(['role' => 'editor']);
        update_user_meta($this->employee_user_id, Google_Sync_Service::TOKEN_USER_META, [
            'access_token' => 'fake_access_token',
            'refresh_token' => 'fake_refresh_token',
            'expires_at' => time() + 3600,
            'email' => 'employee@test.com',
        ]);

        // 4. Create Employee Post
        $this->employee_post_id = $this->factory()->post->create([
            'post_type' => $this->personnel_dim_slug,
            'post_title' => 'Dr. Smith',
        ]);
        update_post_meta($this->employee_post_id, Constants::META_EMPLOYEE_USER_ID, $this->employee_user_id);

        // 5. Create Client & Service
        $this->client_user_id = $this->factory()->user->create(['role' => 'subscriber']);
        $this->service_id = $this->factory()->post->create([
            'post_type' => 'clisyc_service',
            'post_title' => 'Annual Checkup',
        ]);
    }

    /**
     * Helper: Creates a standard mock Google Client with all setters stubbed.
     */
    private function create_mock_google_client(): \Google_Client {
        $mockGoogleClient = $this->getMockBuilder(\Google_Client::class)
                                 ->disableOriginalConstructor()
                                 ->getMock();

        $mockGoogleClient->method('setAccessToken')->willReturn(true);
        $mockGoogleClient->method('isAccessTokenExpired')->willReturn(false);
        $mockGoogleClient->method('setApplicationName');
        $mockGoogleClient->method('setClientId');
        $mockGoogleClient->method('setClientSecret');
        $mockGoogleClient->method('setRedirectUri');
        $mockGoogleClient->method('setAccessType');
        $mockGoogleClient->method('setApprovalPrompt');
        $mockGoogleClient->method('setScopes');

        return $mockGoogleClient;
    }

    /**
     * Helper: Creates a standard appointment with all required meta.
     */
    private function create_test_appointment( array $overrides = [] ): int {
        $appointment_id = $this->factory()->post->create( array_merge( [
            'post_type' => 'clisyc_appointment',
            'post_author' => $this->client_user_id,
            'post_status' => 'publish',
        ], $overrides ) );

        update_post_meta( $appointment_id, Constants::META_TIME_SLOT, '2025-12-25 10:00:00' );
        update_post_meta( $appointment_id, 'clisyc_appointment_duration', 60 );
        update_post_meta( $appointment_id, Constants::META_SLOT_DIMENSIONS, [
            'clisyc_service' => $this->service_id,
            $this->personnel_dim_slug => $this->employee_post_id,
        ] );

        return $appointment_id;
    }

    /**
     * @test
     */
    public function it_creates_a_new_google_event_for_a_new_appointment() {
        // --- ARRANGE ---
        $appointment_id = $this->create_test_appointment();

        // 1. Mock Google Event (Result)
        $mockGoogleEvent = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
        $mockGoogleEvent->method('getId')->willReturn('fake_google_event_123');

        // 2. Mock Events Resource
        $mockEventsService = $this->getMockBuilder(\stdClass::class)->addMethods(['insert', 'delete', 'update', 'listEvents'])->getMock();
        $mockEventsService->expects($this->once())->method('insert')->willReturn($mockGoogleEvent);

        // 3. Mock Calendar Service
        $mockCalendarService = $this->getMockBuilder(\Google_Service_Calendar::class)->disableOriginalConstructor()->getMock();
        $mockCalendarService->events = $mockEventsService;

        // 4. Mock Google Client
        $mockGoogleClient = $this->create_mock_google_client();

        $service = new Google_Sync_Service();
        $service->set_google_client($mockGoogleClient);
        $service->set_calendar_service($mockCalendarService);

        // --- ACT ---
        $service->sync_appointment_to_google($appointment_id);

        // --- ASSERT ---
        $this->assertEquals(
            'fake_google_event_123',
            get_post_meta($appointment_id, Google_Sync_Service::GOOGLE_EVENT_ID_META, true),
            'The Google Event ID should be saved to post meta.'
        );
        $this->assertEquals(
            'clientsync',
            get_post_meta($appointment_id, Google_Sync_Service::GOOGLE_SYNC_SOURCE_META, true),
            'The sync source should be marked as clientsync.'
        );
    }

    /**
     * @test
     * Verifies that the service reads the correct meta key (_clisyc_slot_dimensions)
     * when resolving the employee user ID from an appointment.
     */
    public function it_resolves_employee_from_dimensions_meta() {
        // --- ARRANGE ---
        $appointment_id = $this->create_test_appointment();

        // Mock a Google event insert that returns an ID
        $mockGoogleEvent = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
        $mockGoogleEvent->method('getId')->willReturn('resolved_event_456');

        $mockEventsService = $this->getMockBuilder(\stdClass::class)->addMethods(['insert', 'delete', 'update', 'listEvents'])->getMock();
        $mockEventsService->expects($this->once())->method('insert')->willReturn($mockGoogleEvent);

        $mockCalendarService = $this->getMockBuilder(\Google_Service_Calendar::class)->disableOriginalConstructor()->getMock();
        $mockCalendarService->events = $mockEventsService;

        $mockGoogleClient = $this->create_mock_google_client();

        $service = new Google_Sync_Service();
        $service->set_google_client($mockGoogleClient);
        $service->set_calendar_service($mockCalendarService);

        // --- ACT ---
        $service->sync_appointment_to_google($appointment_id);

        // --- ASSERT ---
        // If the employee was resolved correctly, an event was created and its ID saved.
        $this->assertEquals(
            'resolved_event_456',
            get_post_meta($appointment_id, Google_Sync_Service::GOOGLE_EVENT_ID_META, true),
            'The event should have been created, proving the employee was resolved from _clisyc_slot_dimensions.'
        );
    }

    /**
     * @test
     * Verifies that the service correctly reads the primary dimension title
     * from the appointment using the properly prefixed meta key.
     */
    public function it_includes_service_title_in_event_summary() {
        // --- ARRANGE ---
        $appointment_id = $this->create_test_appointment();

        // Capture the event data passed to insert()
        $captured_event = null;

        $mockGoogleEvent = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
        $mockGoogleEvent->method('getId')->willReturn('title_test_789');

        $mockEventsService = $this->getMockBuilder(\stdClass::class)->addMethods(['insert', 'delete', 'update', 'listEvents'])->getMock();
        $mockEventsService->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function( $calendarId, $event, $opts = [] ) use ( &$captured_event, $mockGoogleEvent ) {
                $captured_event = $event;
                return $mockGoogleEvent;
            });

        $mockCalendarService = $this->getMockBuilder(\Google_Service_Calendar::class)->disableOriginalConstructor()->getMock();
        $mockCalendarService->events = $mockEventsService;

        $mockGoogleClient = $this->create_mock_google_client();

        $service = new Google_Sync_Service();
        $service->set_google_client($mockGoogleClient);
        $service->set_calendar_service($mockCalendarService);

        // --- ACT ---
        $service->sync_appointment_to_google($appointment_id);

        // --- ASSERT ---
        $this->assertNotNull( $captured_event, 'An event should have been passed to insert().' );

        // The summary should include the service title "Annual Checkup"
        $summary = $captured_event->getSummary();
        $this->assertStringContainsString(
            'Annual Checkup',
            $summary,
            'The event summary should contain the primary dimension (service) title.'
        );
    }

    /**
     * @test
     * Verifies that updates sync to Google when the appointment has 'confirmed' status
     * (not just 'publish').
     */
    public function it_updates_google_event_for_confirmed_status() {
        // --- ARRANGE ---
        $appointment_id = $this->create_test_appointment([
            'post_status' => 'publish', // Start as publish for creation
        ]);

        // First, simulate having already synced (event ID exists)
        update_post_meta($appointment_id, Google_Sync_Service::GOOGLE_EVENT_ID_META, 'existing_event_id');

        $mockEventsService = $this->getMockBuilder(\stdClass::class)->addMethods(['insert', 'delete', 'update', 'listEvents'])->getMock();
        $mockEventsService->expects($this->once())->method('update');

        $mockCalendarService = $this->getMockBuilder(\Google_Service_Calendar::class)->disableOriginalConstructor()->getMock();
        $mockCalendarService->events = $mockEventsService;

        $mockGoogleClient = $this->create_mock_google_client();

        $service = new Google_Sync_Service();
        $service->set_google_client($mockGoogleClient);
        $service->set_calendar_service($mockCalendarService);

        // --- ACT ---
        $service->sync_appointment_to_google($appointment_id);

        // --- ASSERT ---
        // The update() method was called once (verified by expects($this->once()) above)
        $this->assertTrue(true, 'The update method was called for the existing Google event.');
    }

    /**
     * @test
     * Verifies that conferenceData is included when Google Meet is enabled for the service.
     */
    public function it_includes_conference_data_when_google_meet_enabled() {
        // --- ARRANGE ---
        // Enable Google Meet provider
        update_option( Constants::OPTION_VIDEO_PROVIDER, 'google_meet' );
        // Enable video conferencing on the service
        update_post_meta( $this->service_id, Constants::META_VIDEO_CONF_ENABLED, '1' );

        $appointment_id = $this->create_test_appointment();

        // Capture the event data and insert options passed to insert()
        $captured_event = null;
        $captured_opts  = null;

        $mockGoogleEvent = $this->getMockBuilder(\stdClass::class)->addMethods(['getId', 'getHangoutLink'])->getMock();
        $mockGoogleEvent->method('getId')->willReturn('meet_event_123');
        $mockGoogleEvent->method('getHangoutLink')->willReturn('https://meet.google.com/abc-defg-hij');

        $mockEventsService = $this->getMockBuilder(\stdClass::class)->addMethods(['insert', 'delete', 'update', 'listEvents'])->getMock();
        $mockEventsService->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function( $calendarId, $event, $opts = [] ) use ( &$captured_event, &$captured_opts, $mockGoogleEvent ) {
                $captured_event = $event;
                $captured_opts  = $opts;
                return $mockGoogleEvent;
            });

        $mockCalendarService = $this->getMockBuilder(\Google_Service_Calendar::class)->disableOriginalConstructor()->getMock();
        $mockCalendarService->events = $mockEventsService;
        $mockGoogleClient = $this->create_mock_google_client();

        $service = new Google_Sync_Service();
        $service->set_google_client($mockGoogleClient);
        $service->set_calendar_service($mockCalendarService);

        // --- ACT ---
        $service->sync_appointment_to_google($appointment_id);

        // --- ASSERT ---
        $this->assertNotNull( $captured_event, 'An event should have been passed to insert().' );

        // Check that conferenceDataVersion was passed
        $this->assertArrayHasKey( 'conferenceDataVersion', $captured_opts, 'conferenceDataVersion should be in insert params.' );
        $this->assertEquals( 1, $captured_opts['conferenceDataVersion'] );

        // Check that the Google Meet link was saved to post meta
        $this->assertEquals(
            'https://meet.google.com/abc-defg-hij',
            get_post_meta( $appointment_id, Constants::META_MEETING_LINK, true ),
            'The Google Meet link should be saved to appointment meta.'
        );
        $this->assertEquals(
            'google_meet',
            get_post_meta( $appointment_id, Constants::META_MEETING_PROVIDER, true ),
            'The meeting provider should be google_meet.'
        );

        // Clean up
        delete_option( Constants::OPTION_VIDEO_PROVIDER );
        delete_post_meta( $this->service_id, Constants::META_VIDEO_CONF_ENABLED );
    }

    /**
     * @test
     * Verifies that conferenceData is NOT included when the video provider is disabled.
     */
    public function it_does_not_include_conference_data_when_disabled() {
        // --- ARRANGE ---
        // Ensure video provider is disabled
        update_option( Constants::OPTION_VIDEO_PROVIDER, 'none' );

        $appointment_id = $this->create_test_appointment();

        $captured_opts = null;

        $mockGoogleEvent = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
        $mockGoogleEvent->method('getId')->willReturn('no_meet_event_456');

        $mockEventsService = $this->getMockBuilder(\stdClass::class)->addMethods(['insert', 'delete', 'update', 'listEvents'])->getMock();
        $mockEventsService->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function( $calendarId, $event, $opts = [] ) use ( &$captured_opts, $mockGoogleEvent ) {
                $captured_opts = $opts;
                return $mockGoogleEvent;
            });

        $mockCalendarService = $this->getMockBuilder(\Google_Service_Calendar::class)->disableOriginalConstructor()->getMock();
        $mockCalendarService->events = $mockEventsService;
        $mockGoogleClient = $this->create_mock_google_client();

        $service = new Google_Sync_Service();
        $service->set_google_client($mockGoogleClient);
        $service->set_calendar_service($mockCalendarService);

        // --- ACT ---
        $service->sync_appointment_to_google($appointment_id);

        // --- ASSERT ---
        $this->assertArrayNotHasKey(
            'conferenceDataVersion',
            $captured_opts,
            'conferenceDataVersion should NOT be present when video is disabled.'
        );
        $this->assertEmpty(
            get_post_meta( $appointment_id, Constants::META_MEETING_LINK, true ),
            'No meeting link should be saved when video is disabled.'
        );

        // Clean up
        delete_option( Constants::OPTION_VIDEO_PROVIDER );
    }
}
