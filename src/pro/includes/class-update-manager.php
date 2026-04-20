<?php
/**
 * File: src/pro/includes/class-update-manager.php
 * Handles automatic update checks for Client Sync Pro via a self-hosted update server.
 *
 * Hooks into WordPress's native plugin update system to check for new versions
 * at pass.dependentmedia.com/plugin-updates/client-sync-pro/. Requires an active
 * license to download updates; unlicensed sites see update notices but cannot install.
 *
 * Release packages are authenticated with Ed25519 signatures. The server returns a
 * `signed_payload` (canonical JSON with version, zip_sha256, released_at) plus a
 * detached `signature`. The client verifies against an embedded public key before
 * trusting any version info; after WP downloads the ZIP, the sha256 in the signed
 * payload is re-verified against the actual bytes before install_package runs.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Admin
 */

namespace ClientSyncPro;

use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Manager {

	/**
	 * Remote endpoint serving the update-info JSON.
	 */
	const UPDATE_URL = 'https://pass.dependentmedia.com/plugin-updates/client-sync-pro/update-info.php';

	/**
	 * Transient key for caching the remote update data.
	 */
	const CACHE_KEY = 'clisyc_pro_update_data';

	/**
	 * How long to cache update data (in seconds). 12 hours.
	 */
	const CACHE_TTL = 43200;

	/**
	 * Option key for the last successfully-verified update response.
	 * Used as a safety fallback when a subsequent fetch fails signature
	 * verification — we return this stale-but-trusted value instead of
	 * handing an unverified response to WP_Upgrader.
	 */
	const LAST_GOOD_OPTION = 'clisyc_pro_update_last_good';

	/**
	 * Base64-encoded Ed25519 public key used to verify update-info responses.
	 *
	 * Leave EMPTY for soft-launch mode: signature verification is skipped and
	 * a warning is logged, so existing deploys keep working until the release
	 * pipeline is ready to sign. Once the secret key is generated offline and
	 * this constant is populated, verification becomes mandatory — any response
	 * with a bad or missing signature is rejected.
	 *
	 * See AI_HANDOFF.md → "Pro update signing" for the keypair-generation and
	 * release-signing procedure.
	 */
	const UPDATE_SIGNING_PUBKEY_BASE64 = 'CF6f2Vm1vmeanCPxorS6FhSMvMTONmL3ouNIg05NXdU=';

	/**
	 * Plugin basename relative to the plugins directory.
	 * Set once in register_hooks() to avoid repeated calls.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Register WordPress hooks for the update system.
	 */
	public function register_hooks() {
		$this->plugin_file = plugin_basename( dirname( __DIR__ ) . '/client-sync-pro.php' );

		add_filter( 'site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );

		// Intercept the download to verify the ZIP's sha256 against the signed payload
		// before WP_Upgrader extracts and installs it.
		add_filter( 'upgrader_pre_download', [ $this, 'verify_package_before_install' ], 10, 4 );

		// Clear the update cache when the license is activated/deactivated
		// so the download URL availability is recalculated immediately.
		add_action( 'update_option_' . License_Manager::OPTION_NAME, [ $this, 'clear_cache' ] );
		add_action( 'delete_option_' . License_Manager::OPTION_NAME, [ $this, 'clear_cache' ] );
	}

	/**
	 * Check the remote server for a newer version and inject it into the
	 * WordPress update transient if available.
	 *
	 * @param object $transient The update_plugins transient data.
	 * @return object Modified transient data.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Determine current installed version.
		$current_version = $transient->checked[ $this->plugin_file ] ?? '';
		if ( empty( $current_version ) ) {
			return $transient;
		}

		// Fetch remote update info (cached).
		$remote = $this->get_remote_info();
		if ( ! $remote || empty( $remote->verified_version ) ) {
			return $transient;
		}

		if ( version_compare( $remote->verified_version, $current_version, '>' ) ) {
			$update = (object) [
				'slug'         => 'client-sync-pro',
				'plugin'       => $this->plugin_file,
				'new_version'  => $remote->verified_version,
				'url'          => $remote->homepage ?? '',
				'tested'       => $remote->tested ?? '',
				'requires'     => $remote->requires ?? '',
				'requires_php' => $remote->requires_php ?? '',
			];

			// Only provide the download package if the license is active.
			if ( License_Manager::is_license_active() && ! empty( $remote->download_url ) ) {
				$update->package = $remote->download_url;
			} else {
				$update->package = '';
			}

			$transient->response[ $this->plugin_file ] = $update;
		} else {
			// No update available — remove stale data if present.
			unset( $transient->response[ $this->plugin_file ] );

			// WordPress 5.5+ uses no_update for "up to date" plugins.
			$transient->no_update[ $this->plugin_file ] = (object) [
				'slug'        => 'client-sync-pro',
				'plugin'      => $this->plugin_file,
				'new_version' => $remote->verified_version,
				'url'         => $remote->homepage ?? '',
				'package'     => '',
			];
		}

		return $transient;
	}

	/**
	 * Handle the "View Details" popup in the WordPress plugins screen.
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'client-sync-pro' !== $args->slug ) {
			return $result;
		}

		$remote = $this->get_remote_info();
		if ( ! $remote || empty( $remote->name ) ) {
			return $result;
		}

		$info = new \stdClass();
		$info->name          = $remote->name;
		$info->slug          = $remote->slug ?? 'client-sync-pro';
		$info->version       = $remote->verified_version ?? $remote->version;
		$info->author        = $remote->author ?? '';
		$info->requires      = $remote->requires ?? '';
		$info->tested        = $remote->tested ?? '';
		$info->requires_php  = $remote->requires_php ?? '';
		$info->homepage      = $remote->homepage ?? '';
		$info->last_updated  = $remote->last_updated ?? '';

		// Only show download link if licensed.
		if ( License_Manager::is_license_active() && ! empty( $remote->download_url ) ) {
			$info->download_link = $remote->download_url;
		}

		// Sections (description, changelog, etc.).
		if ( isset( $remote->sections ) && ( is_object( $remote->sections ) || is_array( $remote->sections ) ) ) {
			$info->sections = (array) $remote->sections;
		} else {
			$info->sections = [
				'description' => 'Client Sync Pro unlocks advanced features for the Client Sync appointment booking plugin.',
			];
		}

		return $info;
	}

	/**
	 * Fetch the remote update info, using a transient cache.
	 *
	 * On verification failure, does NOT cache the failure and returns the last
	 * successfully-verified response (if one was stored), so a compromised or
	 * misbehaving update server cannot push a bad update simply by serving
	 * malformed bytes.
	 *
	 * @return object|null Decoded + verified object or null on failure.
	 */
	private function get_remote_info() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		// Build request headers with license info for the server.
		$license_data = get_option( License_Manager::OPTION_NAME, [] );
		$headers      = [
			'X-License-Key' => $license_data['key'] ?? '',
			'X-Site-URL'    => home_url(),
		];

		$response = wp_remote_get( self::UPDATE_URL, [
			'timeout' => 15,
			'headers' => $headers,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache a failure for 1 hour to avoid hammering the server.
			set_transient( self::CACHE_KEY, null, HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		$processed = self::verify_and_process( $body );

		if ( null === $processed ) {
			// Verification failed (or response is malformed).
			// Fall back to the last known-good response if available, so a bad
			// server response cannot downgrade or disable updates entirely.
			// Short-rate-limit the next fetch attempt.
			set_transient( self::CACHE_KEY, null, HOUR_IN_SECONDS );
			$last_good = get_option( self::LAST_GOOD_OPTION, null );
			return $last_good ?: null;
		}

		// Successful fetch — cache and stamp as last-good.
		set_transient( self::CACHE_KEY, $processed, self::CACHE_TTL );
		update_option( self::LAST_GOOD_OPTION, $processed, false );

		return $processed;
	}

	/**
	 * Verify the signature on a decoded update-info response and return a
	 * processed object with trusted fields copied from the signed payload.
	 *
	 * Static + parameterised so it can be unit-tested without spinning up the
	 * full update flow. Pass a specific public key to override the embedded
	 * constant (tests use this to inject a test-generated keypair).
	 *
	 * Trusted fields on the returned object:
	 *   - verified_version      (from signed_payload; falls back to outer in soft launch)
	 *   - verified_zip_sha256   (from signed_payload; null in soft launch)
	 *   - _signature_verified   (bool — true only when a signature actually passed)
	 *
	 * Outer-JSON fields (name, homepage, icon, sections, download_url, etc.)
	 * remain on the returned object for display/fetch purposes but are NEVER
	 * used as the source of truth for version or integrity decisions.
	 *
	 * @param mixed       $body       Decoded JSON (stdClass) from the update server, or null.
	 * @param string|null $pubkey_b64 Override public key. Null → use the class constant.
	 * @return object|null Null means "reject this response"; caller decides fallback.
	 */
	public static function verify_and_process( $body, ?string $pubkey_b64 = null ) {
		if ( null === $pubkey_b64 ) {
			$pubkey_b64 = self::UPDATE_SIGNING_PUBKEY_BASE64;
		}

		if ( ! is_object( $body ) || empty( $body->version ) ) {
			return null;
		}

		// Soft-launch mode: no public key configured yet. Accept the outer JSON
		// as-is but loudly flag that verification is disabled.
		if ( '' === (string) $pubkey_b64 ) {
			Debug_Logger::log(
				'UPDATE_SIGNING_PUBKEY_BASE64 is empty — Ed25519 signature verification is DISABLED. '
					. 'Updates are being accepted based on TLS alone. Populate the public key constant '
					. 'in src/pro/includes/class-update-manager.php to enforce signatures. '
					. 'See AI_HANDOFF.md → "Pro update signing".',
				'Updates'
			);

			$body->_signature_verified = false;
			$body->verified_version    = $body->version;
			$body->verified_zip_sha256 = isset( $body->zip_sha256 ) ? (string) $body->zip_sha256 : null;
			return $body;
		}

		// Enforcement path: require sodium and a well-formed signature + payload.
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			Debug_Logger::log(
				'libsodium is not available on this host — cannot verify update signatures. '
					. 'Rejecting update to prevent silent downgrade. Ensure PHP 7.2+ with the '
					. 'sodium extension enabled.',
				'Updates'
			);
			return null;
		}

		if ( empty( $body->signature ) || ! isset( $body->signed_payload ) || '' === (string) $body->signed_payload ) {
			Debug_Logger::log(
				'Update response is missing signature or signed_payload — rejecting.',
				'Updates'
			);
			return null;
		}

		$pubkey    = base64_decode( (string) $pubkey_b64, true );
		$signature = base64_decode( (string) $body->signature, true );

		if ( false === $pubkey || false === $signature ) {
			Debug_Logger::log( 'Malformed base64 in public key or signature — rejecting.', 'Updates' );
			return null;
		}

		if ( strlen( $pubkey ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
			Debug_Logger::log(
				sprintf(
					'Public key has wrong byte length (%d, expected %d) — rejecting.',
					strlen( $pubkey ),
					SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
				),
				'Updates'
			);
			return null;
		}

		if ( strlen( $signature ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
			Debug_Logger::log(
				sprintf(
					'Signature has wrong byte length (%d, expected %d) — rejecting.',
					strlen( $signature ),
					SODIUM_CRYPTO_SIGN_BYTES
				),
				'Updates'
			);
			return null;
		}

		$signed_payload = (string) $body->signed_payload;

		try {
			$valid = sodium_crypto_sign_verify_detached( $signature, $signed_payload, $pubkey );
		} catch ( \Exception $e ) {
			Debug_Logger::log( 'Signature verification threw: ' . $e->getMessage(), 'Updates' );
			return null;
		} catch ( \SodiumException $e ) {
			Debug_Logger::log( 'Signature verification threw SodiumException: ' . $e->getMessage(), 'Updates' );
			return null;
		}

		if ( ! $valid ) {
			Debug_Logger::log(
				'Update response FAILED Ed25519 signature verification — rejecting. '
					. 'Either the update server is compromised, the response was tampered in transit, '
					. 'or the public key in this plugin is wrong.',
				'Updates'
			);
			return null;
		}

		// Parse the *exact bytes that were signed* to extract trusted fields.
		$verified = json_decode( $signed_payload );
		if ( ! is_object( $verified ) || empty( $verified->version ) || empty( $verified->zip_sha256 ) ) {
			Debug_Logger::log(
				'Signed payload JSON is malformed or missing required fields (version, zip_sha256) — rejecting.',
				'Updates'
			);
			return null;
		}

		// Defence-in-depth: the signed version should match the outer version
		// the server advertised. If not, something is off — reject rather than
		// preferring one over the other silently.
		if ( (string) $verified->version !== (string) $body->version ) {
			Debug_Logger::log(
				sprintf(
					'Signed version (%s) does not match outer version (%s) — rejecting.',
					$verified->version,
					$body->version
				),
				'Updates'
			);
			return null;
		}

		$body->_signature_verified = true;
		$body->verified_version    = (string) $verified->version;
		$body->verified_zip_sha256 = (string) $verified->zip_sha256;

		return $body;
	}

	/**
	 * Intercept the WP_Upgrader download step and verify the ZIP's sha256
	 * against the signature-protected zip_sha256 from the signed payload.
	 *
	 * Hook: upgrader_pre_download. If this filter returns anything other than
	 * false/WP_Error, WP treats it as the path to the already-downloaded file
	 * and skips its own download_package() call. On WP_Error, the install
	 * aborts cleanly.
	 *
	 * @param mixed  $reply      Default false (let WP download).
	 * @param string $package    The package URL.
	 * @param object $upgrader   The upgrader instance.
	 * @param array  $hook_extra Hook-specific metadata (plugin, type, etc.).
	 * @return mixed Local file path on success, WP_Error on rejection, or original $reply.
	 */
	public function verify_package_before_install( $reply, $package, $upgrader, $hook_extra ) {
		// Only act on our own plugin to avoid interfering with other upgrades.
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
			return $reply;
		}

		$remote = $this->get_remote_info();
		if ( ! $remote ) {
			return new \WP_Error(
				'clisyc_pro_update_blocked',
				__( 'Client Sync Pro update blocked: no verified update info available.', 'client-sync-pro' )
			);
		}

		$expected_hash = isset( $remote->verified_zip_sha256 ) ? (string) $remote->verified_zip_sha256 : '';

		if ( '' === $expected_hash ) {
			// No hash available. In soft-launch mode (no pubkey configured) we
			// let WP proceed — verification is explicitly disabled and any
			// fail-closed here would brick existing installs the moment the
			// first signed update ships. With a pubkey configured, a missing
			// hash is a bug in the server response and must be blocked.
			if ( '' === (string) self::UPDATE_SIGNING_PUBKEY_BASE64 ) {
				Debug_Logger::log(
					'Downloading Client Sync Pro update WITHOUT hash verification '
						. '(soft-launch mode — UPDATE_SIGNING_PUBKEY_BASE64 is empty).',
					'Updates'
				);
				return $reply;
			}
			return new \WP_Error(
				'clisyc_pro_missing_hash',
				__( 'Client Sync Pro update blocked: verified payload is missing zip_sha256.', 'client-sync-pro' )
			);
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = download_url( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$actual_hash = hash_file( 'sha256', $tmp );
		if ( ! is_string( $actual_hash ) || ! hash_equals( strtolower( $expected_hash ), strtolower( $actual_hash ) ) ) {
			// Defensive cleanup; ignore unlink failures.
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			Debug_Logger::log(
				sprintf(
					'Client Sync Pro update blocked: sha256 mismatch. Expected %s, got %s. '
						. 'Update server may be serving a tampered ZIP — aborting install.',
					$expected_hash,
					is_string( $actual_hash ) ? $actual_hash : 'unknown'
				),
				'Updates'
			);
			return new \WP_Error(
				'clisyc_pro_hash_mismatch',
				__( 'Client Sync Pro update blocked: package hash does not match signed payload.', 'client-sync-pro' )
			);
		}

		return $tmp;
	}

	/**
	 * Clear the cached update data.
	 * Called when the license status changes so the download URL
	 * availability is recalculated on the next check.
	 */
	public function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
