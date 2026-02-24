<?php
/**
 * File: src/shared/includes/core/class-bulk-action-handler.php
 * Handles bulk actions for the appointment post type admin list.
 *
 * Extracted from PostType_Manager to reduce class size.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */
namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Cancellation_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bulk_Action_Handler {

	/**
	 * Register WordPress hooks for bulk actions.
	 */
	public function register_hooks() {
		add_filter( 'bulk_actions-edit-clisyc_appointment', [ $this, 'register_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-edit-clisyc_appointment', [ $this, 'handle_bulk_actions' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'display_bulk_action_admin_notice' ] );
	}

	public function register_bulk_actions( $bulk_actions ) {
		$bulk_actions['clisyc_mark_completed']    = __( 'Mark as Completed (Draft)', 'client-sync' );
		$bulk_actions['clisyc_cancel']            = __( 'Cancel & Restore Slot (Trash)', 'client-sync' );
		$bulk_actions['clisyc_promote_waitlist']  = __( 'Promote from Waitlist', 'client-sync' );
		return $bulk_actions;
	}

	public function handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
		if ( ! in_array( $doaction, [ 'clisyc_mark_completed', 'clisyc_cancel', 'clisyc_promote_waitlist' ], true ) ) {
			return $redirect_to;
		}
		$updated   = 0;
		$cancelled = 0;
		$promoted  = 0;

		foreach ( (array) $post_ids as $post_id ) {
			if ( Constants::POST_TYPE_APPOINTMENT !== get_post_type( $post_id ) ) {
				continue;
			}

			if ( 'clisyc_mark_completed' === $doaction ) {
				wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
				$updated++;
			} elseif ( 'clisyc_cancel' === $doaction ) {
				$manager = new Cancellation_Manager( $post_id );
				$result = $manager->process_cancellation( true );
				if ( $result['success'] ) {
					$cancelled++;
				}
			} elseif ( 'clisyc_promote_waitlist' === $doaction ) {
				if ( Constants::STATUS_WAITLISTED !== get_post_status( $post_id ) ) {
					continue;
				}
				$result = Waitlist_Manager::admin_promote( $post_id );
				if ( $result['success'] ) {
					$promoted++;
				}
			}
		}
		$feedback = [];
		if ( $updated > 0 ) {
			$feedback['clisyc_updated'] = $updated;
		}
		if ( $cancelled > 0 ) {
			$feedback['clisyc_cancelled'] = $cancelled;
		}
		if ( $promoted > 0 ) {
			$feedback['clisyc_promoted'] = $promoted;
		}

		return add_query_arg( $feedback, $redirect_to );
	}

	public function display_bulk_action_admin_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['clisyc_updated'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$count = absint( $_REQUEST['clisyc_updated'] );
			/* translators: %d: The number of appointments updated. */
			$message = sprintf( _n( '%d appointment marked as completed.', '%d appointments marked as completed.', $count, 'client-sync' ), $count );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['clisyc_cancelled'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$count = absint( $_REQUEST['clisyc_cancelled'] );
			/* translators: %d: The number of appointments cancelled. */
			$message = sprintf( _n( '%d appointment cancelled and its slot made available.', '%d appointments cancelled and their slots made available.', $count, 'client-sync' ), $count );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['clisyc_promoted'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$count = absint( $_REQUEST['clisyc_promoted'] );
			/* translators: %d: The number of appointments promoted from the waitlist. */
			$message = sprintf( _n( '%d appointment promoted from the waitlist.', '%d appointments promoted from the waitlist.', $count, 'client-sync' ), $count );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
}
