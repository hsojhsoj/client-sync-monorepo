<?php
/**
 * File: tests/unit/UpdateManagerSignatureTest.php
 * Unit tests for Ed25519 signature verification on Pro auto-update responses.
 *
 * Targets mitigation M1 from SECURITY-REVIEW.md: prior to signing, a compromise
 * of pass.dependentmedia.com (or DNS / a CA in the chain) would have given an
 * attacker RCE on every licensed Pro install. These tests lock in the accept
 * / tamper-reject contract for the Ed25519 verifier.
 *
 * @package    ClientSyncPro\Tests\Unit
 */

namespace ClientSyncPro\Tests\Unit;

use WP_UnitTestCase;
use ClientSyncPro\Update_Manager;

// The Pro plugin only require_once's class-update-manager.php inside its
// plugins_loaded callback, which requires the Free plugin's classes to be
// present first. In the test environment this normally works, but to keep the
// signature tests hermetic we load the file directly. Guarded to avoid double
// declaration if the normal load path has already fired.
if ( ! class_exists( Update_Manager::class ) ) {
	require_once dirname( __DIR__, 2 ) . '/src/pro/includes/class-update-manager.php';
}

class UpdateManagerSignatureTest extends WP_UnitTestCase {

	/**
	 * Ed25519 public key (base64).
	 *
	 * @var string
	 */
	private $pubkey_b64;

	/**
	 * Ed25519 secret key (raw bytes).
	 *
	 * @var string
	 */
	private $secret_key;

	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'libsodium not available on this host.' );
		}

		$keypair          = sodium_crypto_sign_keypair();
		$this->secret_key = sodium_crypto_sign_secretkey( $keypair );
		$this->pubkey_b64 = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
	}

	/**
	 * Build a server-shape response object with a valid signature over a
	 * canonical signed_payload. Mirrors the format produced by the release
	 * signing step in AI_HANDOFF.md.
	 *
	 * @param string $version
	 * @param string $zip_sha256
	 * @return object
	 */
	private function make_signed_response( string $version = '1.6.3', string $zip_sha256 = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2' ) {
		$signed_payload = sprintf(
			'{"version":"%s","zip_sha256":"%s","released_at":"2026-04-19T22:30:00Z"}',
			$version,
			$zip_sha256
		);
		$signature = sodium_crypto_sign_detached( $signed_payload, $this->secret_key );

		return (object) [
			'name'           => 'Client Sync Pro',
			'slug'           => 'client-sync-pro',
			'version'        => $version,
			'homepage'       => 'https://dependentmedia.com/client-sync/',
			'download_url'   => 'https://pass.dependentmedia.com/plugin-updates/client-sync-pro/client-sync-pro.zip',
			'signed_payload' => $signed_payload,
			'signature'      => base64_encode( $signature ),
		];
	}

	/**
	 * @test
	 */
	public function it_accepts_a_correctly_signed_response() {
		$body = $this->make_signed_response();

		$result = Update_Manager::verify_and_process( $body, $this->pubkey_b64 );

		$this->assertNotNull( $result, 'Valid signature should be accepted.' );
		$this->assertTrue( $result->_signature_verified, '_signature_verified flag should be true.' );
		$this->assertSame( '1.6.3', $result->verified_version );
		$this->assertSame(
			'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2',
			$result->verified_zip_sha256
		);
	}

	/**
	 * @test
	 */
	public function it_rejects_a_response_whose_payload_was_tampered_with() {
		$body = $this->make_signed_response();

		// Flip a single character inside signed_payload. The signature was
		// computed over the original bytes, so it will no longer validate.
		$original = $body->signed_payload;
		$tampered = substr_replace( $original, '9', strpos( $original, '"1.6.3"' ) + 2, 1 );
		$this->assertNotSame( $original, $tampered, 'Tamper step must actually change the payload bytes.' );
		$body->signed_payload = $tampered;

		$result = Update_Manager::verify_and_process( $body, $this->pubkey_b64 );

		$this->assertNull( $result, 'Payload tampering must cause rejection.' );
	}

	/**
	 * @test
	 */
	public function it_rejects_a_response_whose_signature_was_tampered_with() {
		$body = $this->make_signed_response();

		$raw_sig = base64_decode( $body->signature, true );
		$this->assertNotFalse( $raw_sig, 'Test setup produced an invalid base64 signature.' );

		// Flip one bit in the raw signature bytes.
		$flipped_byte = chr( ord( $raw_sig[0] ) ^ 0x01 );
		$tampered_sig = $flipped_byte . substr( $raw_sig, 1 );
		$this->assertNotSame( $raw_sig, $tampered_sig, 'Tamper step must actually change the signature bytes.' );
		$body->signature = base64_encode( $tampered_sig );

		$result = Update_Manager::verify_and_process( $body, $this->pubkey_b64 );

		$this->assertNull( $result, 'Signature tampering must cause rejection.' );
	}

	/**
	 * @test
	 */
	public function it_rejects_a_response_that_omits_the_signature_block_when_a_pubkey_is_configured() {
		$body = $this->make_signed_response();
		unset( $body->signature, $body->signed_payload );

		$result = Update_Manager::verify_and_process( $body, $this->pubkey_b64 );

		$this->assertNull( $result, 'Missing signature block must be rejected once a pubkey is configured.' );
	}

	/**
	 * @test
	 */
	public function it_rejects_a_response_signed_with_a_different_keypair() {
		$other_keypair = sodium_crypto_sign_keypair();
		$other_secret  = sodium_crypto_sign_secretkey( $other_keypair );

		$signed_payload = '{"version":"1.6.3","zip_sha256":"a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2","released_at":"2026-04-19T22:30:00Z"}';
		$signature      = sodium_crypto_sign_detached( $signed_payload, $other_secret );

		$body = (object) [
			'version'        => '1.6.3',
			'signed_payload' => $signed_payload,
			'signature'      => base64_encode( $signature ),
		];

		$result = Update_Manager::verify_and_process( $body, $this->pubkey_b64 );

		$this->assertNull( $result, 'Signature from a different keypair must be rejected.' );
	}

	/**
	 * @test
	 */
	public function it_rejects_when_signed_version_disagrees_with_outer_version() {
		$body = $this->make_signed_response( '1.6.3' );
		// Attacker tries to upgrade the displayed version to look newer without
		// having a signature for that version.
		$body->version = '9.9.9';

		$result = Update_Manager::verify_and_process( $body, $this->pubkey_b64 );

		$this->assertNull( $result, 'Version mismatch between outer and signed payload must be rejected.' );
	}

	/**
	 * @test
	 */
	public function it_accepts_and_flags_soft_launch_when_pubkey_is_empty() {
		$body = (object) [
			'name'         => 'Client Sync Pro',
			'version'      => '1.6.2',
			'download_url' => 'https://pass.dependentmedia.com/plugin-updates/client-sync-pro/client-sync-pro.zip',
			// No signature fields at all — this mirrors a legacy update-info.php.
		];

		// Empty pubkey === soft launch mode.
		$result = Update_Manager::verify_and_process( $body, '' );

		$this->assertNotNull( $result, 'Soft-launch mode must still accept responses.' );
		$this->assertFalse( $result->_signature_verified, 'Soft launch must not claim signatures were verified.' );
		$this->assertSame( '1.6.2', $result->verified_version );
		$this->assertNull( $result->verified_zip_sha256, 'Soft launch has no trusted hash.' );
	}

	/**
	 * @test
	 */
	public function it_rejects_empty_or_malformed_bodies() {
		$this->assertNull( Update_Manager::verify_and_process( null, $this->pubkey_b64 ) );
		$this->assertNull( Update_Manager::verify_and_process( 'not-an-object', $this->pubkey_b64 ) );
		$this->assertNull( Update_Manager::verify_and_process( (object) [], $this->pubkey_b64 ) );
		$this->assertNull( Update_Manager::verify_and_process( (object) [ 'version' => '' ], $this->pubkey_b64 ) );
	}

	/**
	 * @test
	 */
	public function it_rejects_when_signature_is_base64_garbage() {
		$body = $this->make_signed_response();
		$body->signature = '!!!not-base64!!!';

		$result = Update_Manager::verify_and_process( $body, $this->pubkey_b64 );

		$this->assertNull( $result, 'Non-base64 signature must be rejected, not passed to sodium.' );
	}

	/**
	 * @test
	 */
	public function it_rejects_when_pubkey_is_wrong_length() {
		$body = $this->make_signed_response();

		// 16 random bytes is the wrong length for an Ed25519 public key (32 bytes).
		$short_pubkey_b64 = base64_encode( random_bytes( 16 ) );

		$result = Update_Manager::verify_and_process( $body, $short_pubkey_b64 );

		$this->assertNull( $result, 'Wrong-length public keys must be rejected before reaching sodium.' );
	}
}
