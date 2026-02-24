<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-seat-selection-module.php
 * Module entry point for Seat Selection (Pro).
 *
 * Discovered and initialized by Module_Manager via auto-discovery.
 * Class name derived from directory: seat-selection → Seat_Selection_Module.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

use ClientSyncPro\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seat_Selection_Module implements Module_Interface {

	/**
	 * Initialize the module.
	 *
	 * Gates on Pro license being active. Loads all module classes
	 * and registers their hooks.
	 */
	public function initialize(): void {
		if ( ! function_exists( 'clisyc_pro_is_license_active' ) || ! \clisyc_pro_is_license_active() ) {
			return;
		}

		// Load module classes.
		require_once __DIR__ . '/class-venue-cpt.php';
		require_once __DIR__ . '/class-svg-parser.php';
		require_once __DIR__ . '/class-seat-hold-manager.php';
		require_once __DIR__ . '/class-venue-meta-box.php';
		require_once __DIR__ . '/class-venue-link-meta-box.php';
		require_once __DIR__ . '/class-venue-rest-controller.php';
		require_once __DIR__ . '/class-seat-booking-handler.php';
		require_once __DIR__ . '/class-checkin-page.php';
		require_once __DIR__ . '/class-qr-generator.php';
		require_once __DIR__ . '/class-seat-transfer-meta-box.php';

		// Register CPT.
		$venue_cpt = new Venue_CPT();
		$venue_cpt->register_hooks();

		// Admin meta boxes + assets.
		$meta_box = new Venue_Meta_Box();
		$meta_box->register_hooks();

		// Venue link meta box on primary dimension CPTs (services).
		$venue_link = new Venue_Link_Meta_Box();
		$venue_link->register_hooks();

		// REST API endpoints.
		$rest_controller = new Venue_REST_Controller();
		add_action( 'rest_api_init', [ $rest_controller, 'register_routes' ] );

		// Booking flow integration.
		$booking_handler = new Seat_Booking_Handler();
		$booking_handler->register_hooks();

		// Admin check-in page.
		$checkin_page = new Checkin_Page();
		$checkin_page->register_hooks();

		// QR code generator for email tickets.
		$qr_generator = new QR_Generator();
		$qr_generator->register_hooks();

		// Seat transfer / reassignment meta box on appointment edit screen.
		$seat_transfer = new Seat_Transfer_Meta_Box();
		$seat_transfer->register_hooks();
	}
}
