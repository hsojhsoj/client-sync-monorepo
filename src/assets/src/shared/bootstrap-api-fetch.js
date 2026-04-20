/**
 * bootstrap-api-fetch.js
 *
 * Registers the WordPress REST nonce middleware on the shared `apiFetch`
 * instance so every subsequent call includes `X-WP-Nonce`.
 *
 * Why this exists: WordPress only auto-wires `@wordpress/api-fetch` with a
 * nonce on admin screens (via the `wp-api-fetch` script's inline config).
 * On the frontend booking form we need to pass the `wp_rest` nonce manually
 * so permission callbacks on otherwise-public endpoints (e.g. the seat-hold
 * write routes) can verify the request originated from a page the user
 * actually loaded, not a cross-origin script or a bot bypassing the UI.
 *
 * Call exactly once per SPA bootstrap, as early as possible:
 *
 *     import { bootstrapApiFetch } from '../shared/bootstrap-api-fetch';
 *     bootstrapApiFetch();
 *
 * Reads the nonce from `window.clisycBookingFormData.restNonce` (the value
 * class-shortcodes.php localizes). If the nonce is unavailable, registers
 * a no-op so the app still functions (endpoints with `__return_true` don't
 * need it; endpoints that require it will 403 with a clear message).
 */
import apiFetch from '@wordpress/api-fetch';

let alreadyBootstrapped = false;

export function bootstrapApiFetch() {
	if ( alreadyBootstrapped ) {
		return;
	}
	alreadyBootstrapped = true;

	const nonce =
		( typeof window !== 'undefined' && window.clisycBookingFormData && window.clisycBookingFormData.restNonce ) ||
		( typeof window !== 'undefined' && window.clisycHybridBookingData && window.clisycHybridBookingData.restNonce ) ||
		( typeof window !== 'undefined' && window.clisycFacetedBookingData && window.clisycFacetedBookingData.restNonce ) ||
		'';

	if ( ! nonce ) {
		return;
	}

	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}
