<?php
/**
 * File: tests/unit/EncryptionServiceTest.php
 * Unit tests for the Encryption Service (HIPAA-compliant AES-256-CBC).
 *
 * @package    ClientSync\Tests\Unit
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use DependentMedia\ClientSync\Services\Encryption_Service;

class EncryptionServiceTest extends WP_UnitTestCase {

	/**
	 * @var Encryption_Service
	 */
	private $service;

	public function setUp(): void {
		parent::setUp();

		// Reset the singleton so each test gets a fresh instance
		// (CLISYC_ENCRYPTION_KEY is defined in tests/bootstrap.php)
		$reflection = new \ReflectionClass( Encryption_Service::class );
		$instance_property = $reflection->getProperty( 'instance' );
		$instance_property->setAccessible( true );
		$instance_property->setValue( null, null );

		$this->service = Encryption_Service::get_instance();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function it_is_available_when_key_is_configured() {
		// CLISYC_ENCRYPTION_KEY is defined in bootstrap.php
		$this->assertTrue( $this->service->is_available() );
	}

	/**
	 * @test
	 */
	public function it_encrypts_and_decrypts_a_string_round_trip() {
		// Arrange
		$plaintext = 'Patient notes: Follow-up in 2 weeks.';

		// Act
		$encrypted = $this->service->encrypt( $plaintext );
		$decrypted = $this->service->decrypt( $encrypted );

		// Assert
		$this->assertNotFalse( $encrypted );
		$this->assertNotEquals( $plaintext, $encrypted );
		$this->assertEquals( $plaintext, $decrypted );
	}

	/**
	 * @test
	 */
	public function it_prefixes_encrypted_values_with_marker() {
		// Arrange
		$plaintext = 'Sensitive data';

		// Act
		$encrypted = $this->service->encrypt( $plaintext );

		// Assert
		$this->assertStringStartsWith( Encryption_Service::ENCRYPTED_PREFIX, $encrypted );
	}

	/**
	 * @test
	 */
	public function it_detects_encrypted_values_correctly() {
		// Arrange
		$encrypted = $this->service->encrypt( 'test value' );
		$plain     = 'not encrypted';

		// Assert
		$this->assertTrue( $this->service->is_encrypted( $encrypted ) );
		$this->assertFalse( $this->service->is_encrypted( $plain ) );
		$this->assertFalse( $this->service->is_encrypted( '' ) );
		$this->assertFalse( $this->service->is_encrypted( 42 ) );
		$this->assertFalse( $this->service->is_encrypted( null ) );
	}

	/**
	 * @test
	 */
	public function it_returns_plain_text_as_is_when_decrypting_unencrypted_input() {
		// Arrange
		$plain = 'This is not encrypted at all';

		// Act
		$result = $this->service->decrypt( $plain );

		// Assert — unencrypted input passes through unchanged
		$this->assertEquals( $plain, $result );
	}

	/**
	 * @test
	 */
	public function it_prevents_double_encryption() {
		// Arrange
		$plaintext = 'Double encrypt test';
		$encrypted = $this->service->encrypt( $plaintext );

		// Act — encrypt the already encrypted value
		$double_encrypted = $this->service->encrypt( $encrypted );

		// Assert — should return the same value, not double-encrypt
		$this->assertEquals( $encrypted, $double_encrypted );
	}

	/**
	 * @test
	 */
	public function it_returns_false_for_malformed_encrypted_input() {
		// Arrange — valid prefix but malformed payload
		$malformed = Encryption_Service::ENCRYPTED_PREFIX . 'not:valid';

		// Act
		$result = $this->service->decrypt( $malformed );

		// Assert
		$this->assertFalse( $result );
	}

	/**
	 * @test
	 */
	public function it_returns_false_for_tampered_ciphertext() {
		// Arrange
		$encrypted = $this->service->encrypt( 'original data' );
		// Tamper with the ciphertext portion (last 5 characters)
		$tampered = substr( $encrypted, 0, -5 ) . 'XXXXX';

		// Act
		$result = $this->service->decrypt( $tampered );

		// Assert
		$this->assertFalse( $result );
	}

	/**
	 * @test
	 */
	public function it_produces_unique_ciphertexts_for_same_input() {
		// Arrange — same plaintext should produce different ciphertexts
		// because each encryption uses a random IV
		$plaintext = 'Same input every time';

		// Act
		$enc1 = $this->service->encrypt( $plaintext );
		$enc2 = $this->service->encrypt( $plaintext );

		// Assert
		$this->assertNotEquals( $enc1, $enc2, 'Two encryptions of the same plaintext should differ (unique IVs).' );

		// Both should decrypt to the same value
		$this->assertEquals( $plaintext, $this->service->decrypt( $enc1 ) );
		$this->assertEquals( $plaintext, $this->service->decrypt( $enc2 ) );
	}

	/**
	 * @test
	 */
	public function it_handles_empty_string_encryption() {
		// Arrange
		$plaintext = '';

		// Act
		$encrypted = $this->service->encrypt( $plaintext );
		$decrypted = $this->service->decrypt( $encrypted );

		// Assert
		$this->assertNotFalse( $encrypted );
		$this->assertEquals( '', $decrypted );
	}

	/**
	 * @test
	 */
	public function it_identifies_sensitive_field_patterns() {
		// Assert
		$this->assertTrue( $this->service->should_encrypt_field( '_clisyc_medical_history' ) );
		$this->assertTrue( $this->service->should_encrypt_field( '_clisyc_ssn' ) );
		$this->assertTrue( $this->service->should_encrypt_field( '_clisyc_phi_custom_field' ) );
		$this->assertTrue( $this->service->should_encrypt_field( '_clisyc_insurance_provider' ) );
		$this->assertFalse( $this->service->should_encrypt_field( '_clisyc_some_normal_field' ) );
		$this->assertFalse( $this->service->should_encrypt_field( 'post_title' ) );
	}

	/**
	 * @test
	 */
	public function it_self_tests_successfully() {
		// Act
		$result = $this->service->test();

		// Assert
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'details', $result );
		$this->assertTrue( $result['details']['openssl_available'] );
		$this->assertTrue( $result['details']['gcm_supported'] );
		$this->assertTrue( $result['details']['key_configured'] );
	}
}
