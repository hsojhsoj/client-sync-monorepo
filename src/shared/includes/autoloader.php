<?php
/**
 * PSR-4 Autoloader for Client Sync.
 *
 * Handles the WordPress-style kebab-case directory and filename conventions
 * (e.g. class-my-class.php, interface-my-interface.php, trait-my-trait.php)
 * while mapping PascalCase namespace segments to their corresponding directories.
 *
 * @package ClientSync
 */

// Note: No ABSPATH guard here. This file is loaded via Composer's "files"
// autoload directive, which runs before WordPress (and before our test
// bootstrap) defines ABSPATH. The autoloader only registers an
// spl_autoload function — it doesn't expose any functionality directly,
// so there is no security concern with loading it outside of WordPress.

spl_autoload_register( function ( $class ) {

	// ── Namespace-prefix → base-directory mapping ──
	$prefixes = [
		'DependentMedia\\ClientSync\\' => CLISYC_SHARED_DIR . 'includes/',
	];

	if ( defined( 'CLISYC_PRO_DIR' ) ) {
		$prefixes['ClientSyncPro\\'] = CLISYC_PRO_DIR . 'includes/';
	}

	foreach ( $prefixes as $prefix => $base_dir ) {
		if ( strpos( $class, $prefix ) !== 0 ) {
			continue;
		}

		// Strip the namespace prefix.
		// e.g. "DependentMedia\ClientSync\Core\NotificationChannels\Email_Channel"
		//    → "Core\NotificationChannels\Email_Channel"
		$relative = substr( $class, strlen( $prefix ) );

		// Split into namespace segments + final class name.
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts ); // e.g. "Email_Channel"

		// Convert PascalCase namespace segments to kebab-case directory names.
		//   "NotificationChannels" → "notification-channels"
		//   "RestAPI"              → "rest-api"
		//   "ListTables"           → "list-tables"
		$dir_parts = array_map( function ( $segment ) {
			$h = preg_replace( '/([a-z])([A-Z])/', '$1-$2', $segment );
			$h = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $h );
			return strtolower( $h );
		}, $parts );

		$dir = $base_dir . ( $dir_parts ? implode( '/', $dir_parts ) . '/' : '' );

		// ── Determine filename prefix (interface- / trait- / class-) ──
		$file_prefix   = 'class-';
		$resolved_name = $class_name;

		// Interfaces: class name ends with _Interface (or equals "Interface")
		if ( preg_match( '/_?Interface$/i', $class_name ) ) {
			$file_prefix   = 'interface-';
			$resolved_name = preg_replace( '/_?Interface$/i', '', $class_name );
		}
		// Traits: detected by being inside a "traits" directory
		elseif ( $dir_parts && end( $dir_parts ) === 'traits' ) {
			$file_prefix = 'trait-';
		}
		// Traits: fallback detection — if the class- prefixed file doesn't exist,
		// check for a trait- prefixed file (supports traits in non-"traits" directories).
		// This is checked after building the file path below.

		// Convert the class name to a kebab-case filename:
		//   "Email_Channel"        → "email-channel"     (underscore → hyphen)
		//   "FormRenderer"         → "form-renderer"     (PascalCase → kebab)
		//   "SlotCalculator"       → "slot-calculator"    (PascalCase → kebab)
		//   "PostType_Manager"     → "post-type-manager"  (mixed → kebab)
		//   "CalendarDataProvider" → "calendar-data-provider"
		$file_name = preg_replace( '/([a-z])([A-Z])/', '$1-$2', $resolved_name );
		$file_name = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $file_name );
		$file_name = strtolower( str_replace( '_', '-', $file_name ) );
		$file      = $dir . $file_prefix . $file_name . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
			return;
		}

		// Fallback: if "class-" prefix didn't match, try "trait-" prefix.
		// This allows traits to live in any directory, not only "traits/".
		if ( 'class-' === $file_prefix ) {
			$trait_file = $dir . 'trait-' . $file_name . '.php';
			if ( file_exists( $trait_file ) ) {
				require_once $trait_file;
				return;
			}
		}
	}
} );
