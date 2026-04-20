<?php
/**
 * Client Sync Pro - Update Info Endpoint
 *
 * Upload this file to: pass.dependentmedia.com/plugin-updates/client-sync-pro/
 * Also upload the plugin zip file (client-sync-pro.zip) to the same directory.
 *
 * WordPress sites with Client Sync Pro will check this endpoint for available updates.
 *
 * --- Release signing ---
 *
 * New Pro clients (1.6.3+) verify each update using an Ed25519 signature over
 * a canonical JSON payload. The release pipeline (documented in AI_HANDOFF.md
 * under "Pro update signing") produces two values per release:
 *
 *   $signed_payload = '{"version":"1.6.3","zip_sha256":"<hex>","released_at":"2026-04-19T22:30:00Z"}';
 *   $signature      = base64_encode( sodium_crypto_sign_detached( $signed_payload, $secret_key ) );
 *
 * Both are placed verbatim in the response below. The CLIENT re-parses
 * $signed_payload to extract the trusted version + zip_sha256; everything
 * else in this file (name, homepage, sections, download_url, etc.) is
 * treated as untrusted display metadata.
 *
 * Clients older than 1.6.3 ignore the signature/signed_payload fields and
 * continue to trust TLS alone — this is intentional so that the first
 * signed release can still install on the soft-launch generation.
 */

header( 'Content-Type: application/json' );

// --- Signed release metadata (paste after each release-signing step) ---
// For the transition period these remain empty strings. Once the secret key
// exists and the release has been signed, drop in the exact bytes produced by
// the signing script. DO NOT re-encode or reformat $signed_payload — the
// signature is over the exact bytes that go here.
$signed_payload = '{"version":"1.6.3","zip_sha256":"58688af3fe01431e29c06141c8f93d2949248a0756bf3306ad3488fe1ffedf54","released_at":"2026-04-20T07:31:52Z"}';
$signature      = 'NEF25nDyvqNWHUhPJ9oMgQ+TnRimYvmrQuNaHR/RMtNReckuVMabaWqCzewSTzEYvfy9MZhWz5i1LcaAfaVaBQ==';

$update_info = [
	'name'           => 'Client Sync Pro',
	'slug'           => 'client-sync-pro',
	'version'        => '1.6.3',
	'author'         => '<a href="https://dependentmedia.com">Joshua Jordan</a>',
	'homepage'       => 'https://dependentmedia.com/client-sync/',
	'download_url'   => 'https://pass.dependentmedia.com/plugin-updates/client-sync-pro/client-sync-pro.zip',
	'requires'       => '5.8',
	'tested'         => '6.9',
	'requires_php'   => '7.4',
	'last_updated'   => '2026-04-20',

	// Ed25519 signature + the exact JSON bytes that were signed.
	// Empty strings mean "unsigned release" — signing-aware clients will reject.
	'signature'      => $signature,
	'signed_payload' => $signed_payload,

	'sections'       => [
		'description' => 'Client Sync Pro unlocks advanced features for the Client Sync appointment booking plugin: resource scheduling, custom forms, membership plans, seat selection, notifications (SMS & webhooks), and more.',
		'changelog'   => '<h4>1.6.3</h4>'
			. '<ul>'
			. '<li><strong>Security: Ed25519-signed auto-updates.</strong> Every Pro release is now cryptographically signed with an offline key, and installs verify the signature before applying an update. Closes the supply-chain risk where a compromised update server could push arbitrary code to installed sites. Existing 1.6.2 installs receive this release via the unsigned channel as before; from 1.6.4 onward, unsigned update responses are rejected.</li>'
			. '<li><strong>Security: Appointment REST access tightened.</strong> Cross-client reads now require the manager-view capability (<code>edit_others_posts</code>) instead of <code>edit_posts</code> — closes a PII/PHI disclosure path on multi-author and HIPAA-enabled sites.</li>'
			. '<li><strong>Security: CSV export formula-injection guard.</strong> Appointments, client lists, reporting, and HIPAA audit-log exports now neutralize leading <code>=</code>/<code>+</code>/<code>-</code>/<code>@</code> characters so attacker-influenced fields can\'t execute as formulas in Excel or Google Sheets.</li>'
			. '<li><strong>Security: Seat-hold write endpoints require a REST nonce.</strong> Prevents anonymous bots from scripting fan-out holds across forged session tokens (ticketing DoS).</li>'
			. '<li><strong>Security: XSS hardening in the frontend calendar.</strong> Dimension labels and other admin-controlled strings are now escaped on the booking page.</li>'
			. '<li>Compatibility update for Client Sync 3.7.3.</li>'
			. '</ul>'
			. '<h4>1.6.1</h4>'
			. '<ul>'
			. '<li><strong>Fix: Membership Plans Shortcode Load Order.</strong> Resolved a timing issue where the [clisyc_membership_plans] shortcode was not registered because the Pro plugin\'s CPT registration ran after the free plugin\'s shortcode initialization check.</li>'
			. '<li>Compatibility update for Client Sync 3.7.1.</li>'
			. '</ul>'
			. '<h4>1.6.0</h4>'
			. '<ul>'
			. '<li><strong>New Feature: Check-In Dashboard Stats.</strong> Live stats bar on the Check-In page showing Checked In, Expected, Remaining, and Per Hour counters with a progress bar.</li>'
			. '<li><strong>New Feature: Door List &amp; Bulk Check-In.</strong> Card/List view toggle on the Check-In page with sortable table, bulk check-in, CSV export, and print support.</li>'
			. '<li><strong>New Feature: Venue Location in Emails.</strong> New {venue_name}, {venue_address}, and {venue_map_link} email placeholders.</li>'
			. '<li><strong>New Feature: Email QR Code Tickets.</strong> Inline QR code PNG images embedded in confirmation and reminder emails.</li>'
			. '<li><strong>New Feature: Seat Availability Heatmap.</strong> Overview SVG sections colour-coded by fill percentage.</li>'
			. '<li><strong>New Feature: Seat Transfer / Reassignment.</strong> Admin meta box for releasing and reassigning individual seats.</li>'
			. '<li>Compatibility update for Client Sync 3.7.0.</li>'
			. '</ul>'
			. '<h4>1.5.2</h4>'
			. '<ul>'
			. '<li><strong>New Feature: Dedicated Check-In Page.</strong> Admin page with appointment search, filters, and live QR scanner.</li>'
			. '<li><strong>New Feature: QR Code Generation.</strong> Unique QR tokens per seat booking rendered as SVG QR codes.</li>'
			. '<li>Compatibility update for Client Sync 3.6.2.</li>'
			. '</ul>',
	],
];

echo json_encode( $update_info );
