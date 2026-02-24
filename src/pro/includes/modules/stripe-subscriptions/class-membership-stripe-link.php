<?php
/**
 * File: src/pro/includes/modules/stripe-subscriptions/class-membership-stripe-link.php
 * Adds a meta box to the clisyc_member_plan CPT for linking plans to Stripe Price IDs.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Stripe_Subscriptions
 */

namespace ClientSyncPro\Modules\Stripe_Subscriptions;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Membership_Stripe_Link {

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_clisyc_member_plan', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_clisyc_member_plan', [ $this, 'save_meta_data' ] );
	}

	/**
	 * Registers the Stripe Subscription Link meta box.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'clisyc-membership-stripe-link',
			__( 'Stripe Subscription', 'client-sync-pro' ),
			[ $this, 'render_meta_box' ],
			'clisyc_member_plan',
			'side',
			'default'
		);
	}

	/**
	 * Renders the meta box content.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'clisyc_stripe_link_save', 'clisyc_stripe_link_nonce' );

		$price_id   = get_post_meta( $post->ID, Constants::META_LINKED_STRIPE_PRICE_ID, true );
		$trial_days = get_post_meta( $post->ID, Constants::META_STRIPE_TRIAL_DAYS, true );

		// Check if this plan also has a WC Subscription product linked.
		$wc_product_id = get_post_meta( $post->ID, '_clisyc_linked_subscription_product_id', true );
		?>

		<?php if ( ! empty( $wc_product_id ) && ! empty( $price_id ) ) : ?>
			<div class="notice notice-warning inline" style="margin: 0 0 12px;">
				<p>
					<strong><?php esc_html_e( 'Warning:', 'client-sync-pro' ); ?></strong>
					<?php esc_html_e( 'This plan has both a WooCommerce Subscription product and a Stripe Price ID linked. Only one billing backend should be used per plan.', 'client-sync-pro' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<p>
			<label for="clisyc_linked_stripe_price_id">
				<strong><?php esc_html_e( 'Stripe Price ID', 'client-sync-pro' ); ?></strong>
			</label>
			<input
				type="text"
				id="clisyc_linked_stripe_price_id"
				name="<?php echo esc_attr( Constants::META_LINKED_STRIPE_PRICE_ID ); ?>"
				value="<?php echo esc_attr( $price_id ); ?>"
				class="widefat"
				placeholder="price_xxx"
			>
		</p>
		<p class="description">
			<?php esc_html_e( 'Enter the recurring Price ID from your Stripe Dashboard. Create a Product with a recurring Price in Stripe first.', 'client-sync-pro' ); ?>
		</p>

		<p style="margin-top: 12px;">
			<label for="clisyc_stripe_trial_days">
				<strong><?php esc_html_e( 'Free Trial (days)', 'client-sync-pro' ); ?></strong>
			</label>
			<input
				type="number"
				id="clisyc_stripe_trial_days"
				name="<?php echo esc_attr( Constants::META_STRIPE_TRIAL_DAYS ); ?>"
				value="<?php echo esc_attr( $trial_days ); ?>"
				class="widefat"
				min="0"
				max="730"
				placeholder="0"
			>
		</p>
		<p class="description">
			<?php esc_html_e( 'Set to 0 for no trial. The user will have full membership access during the trial.', 'client-sync-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Saves the Stripe link meta data.
	 *
	 * @param int $post_id Post ID being saved.
	 */
	public function save_meta_data( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['clisyc_stripe_link_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clisyc_stripe_link_nonce'] ), 'clisyc_stripe_link_save' ) ) {
			return;
		}

		// Save Price ID.
		if ( isset( $_POST[ Constants::META_LINKED_STRIPE_PRICE_ID ] ) ) {
			$price_id = sanitize_text_field( wp_unslash( $_POST[ Constants::META_LINKED_STRIPE_PRICE_ID ] ) );
			if ( ! empty( $price_id ) ) {
				update_post_meta( $post_id, Constants::META_LINKED_STRIPE_PRICE_ID, $price_id );
			} else {
				delete_post_meta( $post_id, Constants::META_LINKED_STRIPE_PRICE_ID );
			}
		}

		// Save trial days.
		if ( isset( $_POST[ Constants::META_STRIPE_TRIAL_DAYS ] ) ) {
			$trial_days = absint( $_POST[ Constants::META_STRIPE_TRIAL_DAYS ] );
			update_post_meta( $post_id, Constants::META_STRIPE_TRIAL_DAYS, $trial_days );
		}
	}
}
