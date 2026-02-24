<?php
/**
 * File: tests/unit/FormSubmissionHandlerTest.php
 * Unit tests for the Form Submission Handler (Pro module).
 *
 * Tests lead creation, user account creation, form data storage, and validation.
 *
 * @package    ClientSyncPro\Tests\Unit
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;

class FormSubmissionHandlerTest extends WP_UnitTestCase {

	/** @var \ClientSyncPro\Modules\Forms\Form_Submission_Handler */
	private $handler;

	/** @var int */
	private $form_id;

	/** @var \WP_Post */
	private $form_post;

	/** @var string Class name for easier reference. */
	private static $handler_class = 'ClientSyncPro\\Modules\\Forms\\Form_Submission_Handler';

	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( self::$handler_class ) ) {
			$this->markTestSkipped( 'Form_Submission_Handler class not available. Skipping form tests.' );
		}

		$this->handler = new \ClientSyncPro\Modules\Forms\Form_Submission_Handler();

		// Register clisyc_form post type.
		if ( ! post_type_exists( 'clisyc_form' ) ) {
			register_post_type( 'clisyc_form', [ 'public' => false ] );
		}

		// Register the clisyc_lead role if not already registered.
		if ( ! get_role( 'clisyc_lead' ) ) {
			add_role( 'clisyc_lead', 'Lead', [ 'read' => false ] );
		}

		// Create a test form.
		$this->form_id = $this->factory()->post->create( [
			'post_type'   => 'clisyc_form',
			'post_title'  => 'Test Contact Form',
			'post_status' => 'publish',
		] );
		$this->form_post = get_post( $this->form_id );

		// Standard form fields JSON.
		$form_fields_json = json_encode( [
			[
				'id'       => 'name_1',
				'type'     => 'text',
				'label'    => 'Full Name',
				'required' => true,
			],
			[
				'id'       => 'email_1',
				'type'     => 'email',
				'label'    => 'Email Address',
				'required' => true,
			],
			[
				'id'       => 'message_1',
				'type'     => 'textarea',
				'label'    => 'Message',
				'required' => false,
			],
		] );
		update_post_meta( $this->form_id, '_clisyc_form_builder_json', $form_fields_json );

		// Default settings: create lead on submission.
		update_post_meta( $this->form_id, '_clisyc_form_settings', [
			'submission_action' => 'create_lead',
		] );

		// Reset superglobals.
		$_POST    = [];
		$_REQUEST = [];
	}

	public function tearDown(): void {
		$_POST    = [];
		$_REQUEST = [];
		parent::tearDown();
	}

	// ── Helper ────────────────────────────────────────────────────────

	/**
	 * Invoke a private method on the handler.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments to pass.
	 * @return mixed
	 */
	private function invoke_private( string $method, array $args = [] ) {
		$ref = new \ReflectionMethod( self::$handler_class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->handler, $args );
	}

	/**
	 * Get the feedback transient using the new user-keyed format.
	 *
	 * @param int $form_id Form ID.
	 * @return mixed Transient value or false.
	 */
	private function get_feedback_transient( int $form_id ) {
		$user_key = is_user_logged_in() ? get_current_user_id() : md5( $_SERVER['REMOTE_ADDR'] ?? '0' );
		return get_transient( 'clisyc_form_fb_' . $form_id . '_' . $user_key );
	}

	// ── Lead Creation Tests ──────────────────────────────────────────

	/**
	 * @test
	 * create_lead should create a user with the 'clisyc_lead' role.
	 */
	public function it_creates_lead_with_email_field() {
		$unique_email = 'lead_' . wp_generate_password( 6, false ) . '@example.com';

		$data = [
			'Full Name'     => 'Jane Doe',
			'Email Address' => $unique_email,
			'Message'       => 'Hello',
		];

		$this->invoke_private( 'create_lead', [
			$this->form_post,
			$data,
			'Email Address',
			null,
		] );

		$user = get_user_by( 'email', $unique_email );
		$this->assertNotFalse( $user, 'A lead user should be created.' );
		$this->assertContains( 'clisyc_lead', $user->roles, 'User should have the clisyc_lead role.' );
	}

	/**
	 * @test
	 * create_lead should store form submission data in user meta.
	 */
	public function it_stores_form_data_in_user_meta() {
		$unique_email = 'metacheck_' . wp_generate_password( 6, false ) . '@example.com';

		$data = [
			'Full Name'     => 'Meta Test',
			'Email Address' => $unique_email,
			'Message'       => 'Testing meta storage',
		];

		$this->invoke_private( 'create_lead', [
			$this->form_post,
			$data,
			'Email Address',
			null,
		] );

		$user = get_user_by( 'email', $unique_email );
		$this->assertNotFalse( $user );

		$stored = get_user_meta( $user->ID, '_clisyc_form_submission_data', true );
		$this->assertIsArray( $stored );
		$this->assertEquals( $this->form_id, $stored['form_id'] );
		$this->assertSame( 'Test Contact Form', $stored['form_name'] );
		$this->assertArrayHasKey( 'fields', $stored );
		$this->assertSame( 'Meta Test', $stored['fields']['Full Name'] );
	}

	/**
	 * @test
	 * create_lead should do nothing when no email field key is provided.
	 */
	public function it_skips_lead_creation_when_email_missing() {
		$user_count_before = count_users()['total_users'];

		$this->invoke_private( 'create_lead', [
			$this->form_post,
			[ 'Full Name' => 'No Email User' ],
			null,
			null,
		] );

		$user_count_after = count_users()['total_users'];
		$this->assertSame( $user_count_before, $user_count_after, 'No user should be created without email.' );
	}

	/**
	 * @test
	 * create_lead should not create a duplicate user if the email already exists.
	 */
	public function it_skips_lead_creation_when_email_exists() {
		$existing_email = 'existing_' . wp_generate_password( 6, false ) . '@example.com';
		$this->factory()->user->create( [ 'user_email' => $existing_email ] );

		$user_count_before = count_users()['total_users'];

		$this->invoke_private( 'create_lead', [
			$this->form_post,
			[
				'Full Name'     => 'Existing User',
				'Email Address' => $existing_email,
			],
			'Email Address',
			null,
		] );

		$user_count_after = count_users()['total_users'];
		$this->assertSame( $user_count_before, $user_count_after, 'No new user should be created for existing email.' );
	}

	/**
	 * @test
	 * create_lead should attach form data to an existing user when passed.
	 */
	public function it_attaches_data_to_existing_user() {
		$existing_user_id = $this->factory()->user->create( [
			'user_email' => 'attach_' . wp_generate_password( 6, false ) . '@example.com',
			'role'       => 'subscriber',
		] );

		$data = [
			'Full Name'     => 'Attached Data',
			'Email Address' => 'irrelevant@example.com',
			'Message'       => 'Should be stored on existing user',
		];

		$this->invoke_private( 'create_lead', [
			$this->form_post,
			$data,
			'Email Address',
			$existing_user_id,
		] );

		$stored = get_user_meta( $existing_user_id, '_clisyc_form_submission_data', true );
		$this->assertIsArray( $stored );
		$this->assertEquals( $this->form_id, $stored['form_id'] );
		$this->assertSame( 'Attached Data', $stored['fields']['Full Name'] );
	}

	// ── Full Process Submission Tests ─────────────────────────────────

	/**
	 * @test
	 * process_submission should set error transient when required field is missing.
	 */
	public function it_validates_required_fields() {
		// Override wp_safe_redirect to prevent exit.
		add_filter( 'wp_redirect', function( $location ) {
			throw new \Exception( 'redirect:' . $location );
		} );

		$_POST['clisyc_form_id']    = $this->form_id;
		$_POST['clisyc_form_nonce'] = wp_create_nonce( 'clisyc_form_submit_' . $this->form_id );

		// Submit with required 'Full Name' missing, but email present.
		$_POST['clisyc_form_field_name_1']    = ''; // Required but empty.
		$_POST['clisyc_form_field_email_1']   = 'test@example.com';
		$_POST['clisyc_form_field_message_1'] = 'Hello';

		try {
			$this->handler->process_submission();
		} catch ( \Exception $e ) {
			// Expected redirect exception.
		}

		$feedback = $this->get_feedback_transient( $this->form_id );
		$this->assertNotFalse( $feedback, 'Feedback transient should be set.' );
		$this->assertSame( 'error', $feedback['type'] );
		$this->assertStringContainsString( 'Full Name', $feedback['text'] );

		remove_all_filters( 'wp_redirect' );
	}

	/**
	 * @test
	 * User account creation should fail when email is already registered.
	 */
	public function it_rejects_duplicate_email_on_user_creation() {
		$existing_email = 'dupe_' . wp_generate_password( 6, false ) . '@example.com';
		$this->factory()->user->create( [ 'user_email' => $existing_email ] );

		// Update form to include user_account field.
		$form_fields_json = json_encode( [
			[
				'id'       => 'name_1',
				'type'     => 'text',
				'label'    => 'Full Name',
				'required' => false,
			],
			[
				'type'      => 'user_account',
				'autoLogin' => true,
			],
		] );
		update_post_meta( $this->form_id, '_clisyc_form_builder_json', $form_fields_json );

		// Ensure not logged in.
		wp_set_current_user( 0 );

		// Override redirect.
		add_filter( 'wp_redirect', function( $location ) {
			throw new \Exception( 'redirect:' . $location );
		} );

		$_POST['clisyc_form_id']           = $this->form_id;
		$_POST['clisyc_form_nonce']        = wp_create_nonce( 'clisyc_form_submit_' . $this->form_id );
		$_POST['clisyc_user_email']        = $existing_email;
		$_POST['clisyc_user_login']        = 'newuser_' . wp_generate_password( 4, false );
		$_POST['clisyc_user_pass']         = 'testpassword123';
		$_POST['clisyc_user_pass_confirm'] = 'testpassword123';
		$_POST['clisyc_form_field_name_1'] = 'Test User';

		try {
			$this->handler->process_submission();
		} catch ( \Exception $e ) {
			// Expected redirect.
		}

		$feedback = $this->get_feedback_transient( $this->form_id );
		$this->assertNotFalse( $feedback, 'Feedback transient should be set.' );
		$this->assertSame( 'error', $feedback['type'] );
		$this->assertStringContainsString( 'already registered', $feedback['text'] );

		remove_all_filters( 'wp_redirect' );
	}

	/**
	 * @test
	 * User account creation should fail when username is already taken.
	 */
	public function it_rejects_duplicate_username_on_user_creation() {
		$existing_username = 'dupeuser_' . wp_generate_password( 4, false );
		$this->factory()->user->create( [ 'user_login' => $existing_username ] );

		// Update form to include user_account field.
		$form_fields_json = json_encode( [
			[
				'id'       => 'name_1',
				'type'     => 'text',
				'label'    => 'Full Name',
				'required' => false,
			],
			[
				'type'      => 'user_account',
				'autoLogin' => true,
			],
		] );
		update_post_meta( $this->form_id, '_clisyc_form_builder_json', $form_fields_json );

		// Ensure not logged in.
		wp_set_current_user( 0 );

		// Override redirect.
		add_filter( 'wp_redirect', function( $location ) {
			throw new \Exception( 'redirect:' . $location );
		} );

		$_POST['clisyc_form_id']           = $this->form_id;
		$_POST['clisyc_form_nonce']        = wp_create_nonce( 'clisyc_form_submit_' . $this->form_id );
		$_POST['clisyc_user_email']        = 'unique_' . wp_generate_password( 6, false ) . '@example.com';
		$_POST['clisyc_user_login']        = $existing_username;
		$_POST['clisyc_user_pass']         = 'testpassword123';
		$_POST['clisyc_user_pass_confirm'] = 'testpassword123';
		$_POST['clisyc_form_field_name_1'] = 'Test User';

		try {
			$this->handler->process_submission();
		} catch ( \Exception $e ) {
			// Expected redirect.
		}

		$feedback = $this->get_feedback_transient( $this->form_id );
		$this->assertNotFalse( $feedback, 'Feedback transient should be set.' );
		$this->assertSame( 'error', $feedback['type'] );
		$this->assertStringContainsString( 'already taken', $feedback['text'] );

		remove_all_filters( 'wp_redirect' );
	}

	// ── Nonce Verification Tests ─────────────────────────────────────

	/**
	 * @test
	 * process_submission should set error when nonce is invalid.
	 */
	public function it_rejects_submission_with_invalid_nonce() {
		add_filter( 'wp_redirect', function( $location ) {
			throw new \Exception( 'redirect:' . $location );
		} );

		$_POST['clisyc_form_id']    = $this->form_id;
		$_POST['clisyc_form_nonce'] = 'invalid_nonce_value';
		$_POST['clisyc_form_field_name_1']  = 'Test';
		$_POST['clisyc_form_field_email_1'] = 'test@example.com';

		try {
			$this->handler->process_submission();
		} catch ( \Exception $e ) {
			// Expected redirect.
		}

		$feedback = $this->get_feedback_transient( $this->form_id );
		$this->assertNotFalse( $feedback, 'Feedback transient should be set.' );
		$this->assertSame( 'error', $feedback['type'] );
		$this->assertStringContainsString( 'Security check', $feedback['text'] );

		remove_all_filters( 'wp_redirect' );
	}

	// ── Password Validation Tests ────────────────────────────────────

	/**
	 * @test
	 * User creation should fail when password is too short.
	 */
	public function it_rejects_short_password() {
		$form_fields_json = json_encode( [
			[
				'id'       => 'name_1',
				'type'     => 'text',
				'label'    => 'Full Name',
				'required' => false,
			],
			[
				'type'      => 'user_account',
				'autoLogin' => true,
			],
		] );
		update_post_meta( $this->form_id, '_clisyc_form_builder_json', $form_fields_json );

		wp_set_current_user( 0 );

		add_filter( 'wp_redirect', function( $location ) {
			throw new \Exception( 'redirect:' . $location );
		} );

		$_POST['clisyc_form_id']           = $this->form_id;
		$_POST['clisyc_form_nonce']        = wp_create_nonce( 'clisyc_form_submit_' . $this->form_id );
		$_POST['clisyc_user_email']        = 'shortpass_' . wp_generate_password( 6, false ) . '@example.com';
		$_POST['clisyc_user_login']        = 'shortpass_' . wp_generate_password( 4, false );
		$_POST['clisyc_user_pass']         = 'abc';      // Too short (< 8 chars).
		$_POST['clisyc_user_pass_confirm'] = 'abc';
		$_POST['clisyc_form_field_name_1'] = 'Test';

		try {
			$this->handler->process_submission();
		} catch ( \Exception $e ) {
			// Expected redirect.
		}

		$feedback = $this->get_feedback_transient( $this->form_id );
		$this->assertNotFalse( $feedback );
		$this->assertSame( 'error', $feedback['type'] );
		$this->assertStringContainsString( 'at least 8 characters', $feedback['text'] );

		remove_all_filters( 'wp_redirect' );
	}

	/**
	 * @test
	 * User creation should fail when passwords don't match.
	 */
	public function it_rejects_mismatched_passwords() {
		$form_fields_json = json_encode( [
			[
				'id'       => 'name_1',
				'type'     => 'text',
				'label'    => 'Full Name',
				'required' => false,
			],
			[
				'type'      => 'user_account',
				'autoLogin' => true,
			],
		] );
		update_post_meta( $this->form_id, '_clisyc_form_builder_json', $form_fields_json );

		wp_set_current_user( 0 );

		add_filter( 'wp_redirect', function( $location ) {
			throw new \Exception( 'redirect:' . $location );
		} );

		$_POST['clisyc_form_id']           = $this->form_id;
		$_POST['clisyc_form_nonce']        = wp_create_nonce( 'clisyc_form_submit_' . $this->form_id );
		$_POST['clisyc_user_email']        = 'mismatch_' . wp_generate_password( 6, false ) . '@example.com';
		$_POST['clisyc_user_login']        = 'mismatch_' . wp_generate_password( 4, false );
		$_POST['clisyc_user_pass']         = 'password1234';
		$_POST['clisyc_user_pass_confirm'] = 'different5678';
		$_POST['clisyc_form_field_name_1'] = 'Test';

		try {
			$this->handler->process_submission();
		} catch ( \Exception $e ) {
			// Expected redirect.
		}

		$feedback = $this->get_feedback_transient( $this->form_id );
		$this->assertNotFalse( $feedback );
		$this->assertSame( 'error', $feedback['type'] );
		$this->assertStringContainsString( 'do not match', $feedback['text'] );

		remove_all_filters( 'wp_redirect' );
	}

	// ── Invalid Form Configuration Tests ─────────────────────────────

	/**
	 * @test
	 * process_submission should error when form fields JSON is invalid.
	 */
	public function it_handles_invalid_form_fields_json() {
		// Set invalid JSON.
		update_post_meta( $this->form_id, '_clisyc_form_builder_json', 'not valid json' );

		add_filter( 'wp_redirect', function( $location ) {
			throw new \Exception( 'redirect:' . $location );
		} );

		$_POST['clisyc_form_id']    = $this->form_id;
		$_POST['clisyc_form_nonce'] = wp_create_nonce( 'clisyc_form_submit_' . $this->form_id );

		try {
			$this->handler->process_submission();
		} catch ( \Exception $e ) {
			// Expected redirect.
		}

		$feedback = $this->get_feedback_transient( $this->form_id );
		$this->assertNotFalse( $feedback );
		$this->assertSame( 'error', $feedback['type'] );
		$this->assertStringContainsString( 'configuration error', $feedback['text'] );

		remove_all_filters( 'wp_redirect' );
	}

	/**
	 * @test
	 * process_submission should error when form fields is empty array.
	 */
	public function it_handles_empty_form_fields() {
		update_post_meta( $this->form_id, '_clisyc_form_builder_json', '[]' );

		add_filter( 'wp_redirect', function( $location ) {
			throw new \Exception( 'redirect:' . $location );
		} );

		$_POST['clisyc_form_id']    = $this->form_id;
		$_POST['clisyc_form_nonce'] = wp_create_nonce( 'clisyc_form_submit_' . $this->form_id );

		try {
			$this->handler->process_submission();
		} catch ( \Exception $e ) {
			// Expected redirect.
		}

		$feedback = $this->get_feedback_transient( $this->form_id );
		$this->assertNotFalse( $feedback );
		$this->assertSame( 'error', $feedback['type'] );
		$this->assertStringContainsString( 'configuration error', $feedback['text'] );

		remove_all_filters( 'wp_redirect' );
	}

	// ── Successful User Account Creation ─────────────────────────────

	/**
	 * @test
	 * Successful user account creation should create a subscriber.
	 */
	public function it_creates_user_account_with_subscriber_role() {
		$form_fields_json = json_encode( [
			[
				'id'       => 'name_1',
				'type'     => 'text',
				'label'    => 'Full Name',
				'required' => false,
			],
			[
				'type'      => 'user_account',
				'autoLogin' => false,
			],
		] );
		update_post_meta( $this->form_id, '_clisyc_form_builder_json', $form_fields_json );

		wp_set_current_user( 0 );

		add_filter( 'wp_redirect', function( $location ) {
			throw new \Exception( 'redirect:' . $location );
		} );

		$unique_email    = 'newuser_' . wp_generate_password( 6, false ) . '@example.com';
		$unique_username = 'newuser_' . wp_generate_password( 4, false );

		$_POST['clisyc_form_id']           = $this->form_id;
		$_POST['clisyc_form_nonce']        = wp_create_nonce( 'clisyc_form_submit_' . $this->form_id );
		$_POST['clisyc_user_email']        = $unique_email;
		$_POST['clisyc_user_login']        = $unique_username;
		$_POST['clisyc_user_pass']         = 'securePassword123!';
		$_POST['clisyc_user_pass_confirm'] = 'securePassword123!';
		$_POST['clisyc_form_field_name_1'] = 'New User';

		try {
			$this->handler->process_submission();
		} catch ( \Exception $e ) {
			// Expected redirect.
		}

		$user = get_user_by( 'email', $unique_email );
		$this->assertNotFalse( $user, 'User should be created.' );
		$this->assertContains( 'subscriber', $user->roles, 'New user should have subscriber role.' );
		$this->assertSame( $unique_username, $user->user_login );

		// Verify custom field data was stored as user meta.
		$meta_value = get_user_meta( $user->ID, 'full_name', true );
		$this->assertSame( 'New User', $meta_value );

		remove_all_filters( 'wp_redirect' );
	}
}
