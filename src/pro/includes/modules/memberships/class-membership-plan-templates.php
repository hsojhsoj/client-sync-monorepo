<?php
/**
 * File: src/pro/includes/modules/memberships/class-membership-plan-templates.php
 * Provides starter plan templates for the Memberships module.
 * Shows an empty-state banner on the Membership Plans list screen with a
 * "Create Starter Plans" button that creates 3 pre-configured draft plans.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Memberships
 */

namespace ClientSyncPro\Modules\Memberships;

if ( ! defined( 'ABSPATH' ) ) exit;

class Membership_Plan_Templates {

	/**
	 * Register hooks for the empty-state banner and AJAX handler.
	 */
	public function register_hooks() {
		add_action( 'admin_notices', [ $this, 'maybe_render_starter_banner' ] );
		add_action( 'wp_ajax_clisyc_create_starter_plans', [ $this, 'ajax_create_starter_plans' ] );
		add_action( 'wp_ajax_clisyc_dismiss_starter_plans', [ $this, 'ajax_dismiss_starter_plans' ] );
	}

	/**
	 * Returns the starter plan template definitions.
	 *
	 * @return array[]
	 */
	private static function get_plan_templates(): array {
		return [
			[
				'title'         => __( 'Basic Plan', 'client-sync-pro' ),
				'price_display' => '$29/month',
				'description'   => __( 'Great for occasional visitors', 'client-sync-pro' ),
				'rules'         => [
					[
						'rule_type'      => 'booking_limit',
						'service_id'     => 0,
						'limit_value'    => 4,
						'limit_period'   => 'per_month',
						'discount_value' => 0,
					],
					[
						'rule_type'      => 'discount_percent',
						'service_id'     => 0,
						'limit_value'    => 0,
						'limit_period'   => 'per_month',
						'discount_value' => 10,
					],
				],
			],
			[
				'title'         => __( 'Premium Plan', 'client-sync-pro' ),
				'price_display' => '$79/month',
				'description'   => __( 'Our most popular plan', 'client-sync-pro' ),
				'rules'         => [
					[
						'rule_type'      => 'booking_limit',
						'service_id'     => 0,
						'limit_value'    => 12,
						'limit_period'   => 'per_month',
						'discount_value' => 0,
					],
					[
						'rule_type'      => 'discount_percent',
						'service_id'     => 0,
						'limit_value'    => 0,
						'limit_period'   => 'per_month',
						'discount_value' => 25,
					],
				],
			],
			[
				'title'         => __( 'VIP Unlimited', 'client-sync-pro' ),
				'price_display' => '$149/month',
				'description'   => __( 'The ultimate experience', 'client-sync-pro' ),
				'rules'         => [
					[
						'rule_type'      => 'booking_limit',
						'service_id'     => 0,
						'limit_value'    => 1,
						'limit_period'   => 'unlimited',
						'discount_value' => 0,
					],
					[
						'rule_type'      => 'discount_percent',
						'service_id'     => 0,
						'limit_value'    => 0,
						'limit_period'   => 'per_month',
						'discount_value' => 50,
					],
				],
			],
		];
	}

	/**
	 * Renders the starter plans banner on the Membership Plans list screen when no plans exist.
	 */
	public function maybe_render_starter_banner() {
		$screen = get_current_screen();
		if ( ! is_object( $screen ) || 'edit-clisyc_member_plan' !== $screen->id ) {
			return;
		}

		// Don't show if dismissed.
		if ( get_option( 'clisyc_starter_plans_dismissed', false ) ) {
			return;
		}

		// Don't show if plans already exist.
		$counts = wp_count_posts( 'clisyc_member_plan' );
		$total  = 0;
		foreach ( (array) $counts as $status => $count ) {
			if ( 'trash' !== $status && 'auto-draft' !== $status ) {
				$total += (int) $count;
			}
		}
		if ( $total > 0 ) {
			return;
		}

		$nonce = wp_create_nonce( 'clisyc_create_starter_plans' );
		?>
		<div class="notice clisyc-starter-plans-banner" id="clisyc-starter-plans-banner">
			<div class="clisyc-starter-plans-banner__content">
				<div class="clisyc-starter-plans-banner__icon">
					<span class="dashicons dashicons-awards"></span>
				</div>
				<div class="clisyc-starter-plans-banner__text">
					<h3><?php esc_html_e( 'Get Started with Membership Plans', 'client-sync-pro' ); ?></h3>
					<p>
						<?php esc_html_e( 'Create three ready-made starter plans (Basic, Premium, VIP) with pre-configured booking limits and discounts. Plans are created as drafts so you can customize pricing, add Stripe Price IDs, and publish when ready.', 'client-sync-pro' ); ?>
					</p>
				</div>
				<div class="clisyc-starter-plans-banner__actions">
					<button type="button" class="button button-primary" id="clisyc-create-starter-plans-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<span class="dashicons dashicons-plus-alt2" style="margin-top: 4px;"></span>
						<?php esc_html_e( 'Create Starter Plans', 'client-sync-pro' ); ?>
					</button>
					<button type="button" class="button button-link clisyc-dismiss-starter-plans" id="clisyc-dismiss-starter-plans" data-nonce="<?php echo esc_attr( wp_create_nonce( 'clisyc_dismiss_starter_plans' ) ); ?>">
						<?php esc_html_e( 'Dismiss', 'client-sync-pro' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Create the starter plans.
	 */
	public function ajax_create_starter_plans() {
		check_ajax_referer( 'clisyc_create_starter_plans', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$templates = self::get_plan_templates();
		$created   = [];

		foreach ( $templates as $template ) {
			$post_id = wp_insert_post( [
				'post_title'  => $template['title'],
				'post_type'   => 'clisyc_member_plan',
				'post_status' => 'draft',
			] );

			if ( ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_clisyc_member_plan_access_rules', $template['rules'] );
				update_post_meta( $post_id, '_clisyc_plan_price_display', $template['price_display'] );
				update_post_meta( $post_id, '_clisyc_plan_description', $template['description'] );
				$created[] = $template['title'];
			}
		}

		wp_send_json_success( [
			'message'  => sprintf(
				/* translators: %1$d: number of plans created, %2$s: comma-separated list of plan names. */
				__( 'Created %1$d starter plans: %2$s. Edit each plan to add Stripe pricing and customize rules, then publish.', 'client-sync-pro' ),
				count( $created ),
				implode( ', ', $created )
			),
			'redirect' => admin_url( 'edit.php?post_type=clisyc_member_plan&post_status=draft' ),
		] );
	}

	/**
	 * AJAX handler: Dismiss the starter plans banner.
	 */
	public function ajax_dismiss_starter_plans() {
		check_ajax_referer( 'clisyc_dismiss_starter_plans', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		update_option( 'clisyc_starter_plans_dismissed', true );
		wp_send_json_success();
	}
}
