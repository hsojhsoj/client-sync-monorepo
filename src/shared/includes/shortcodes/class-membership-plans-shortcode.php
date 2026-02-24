<?php
/**
 * Handles the [clisyc_membership_plans] shortcode.
 *
 * Renders a pricing card grid of published Membership Plans with subscribe buttons
 * that integrate with the existing Stripe subscription checkout flow.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Membership_Plans_Shortcode {

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_membership_plans', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the membership plans shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( [
			'columns'   => '3',
			'highlight' => '',
		], $atts, 'clisyc_membership_plans' );

		// Enqueue assets.
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_style( 'clisyc-membership-plans' );
		wp_enqueue_style( 'dashicons' );

		// Query all published plans.
		$plans = get_posts( [
			'post_type'      => 'clisyc_member_plan',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order date',
			'order'          => 'ASC',
		] );

		if ( empty( $plans ) ) {
			return '<p class="clisyc-no-plans">' .
				esc_html__( 'No membership plans are currently available.', 'client-sync' ) .
				'</p>';
		}

		// Detect current user's active membership.
		$user_id         = get_current_user_id();
		$current_plan_id = 0;

		if ( $user_id && class_exists( '\ClientSyncPro\Services\Membership_Rules_Engine' ) ) {
			$engine          = new \ClientSyncPro\Services\Membership_Rules_Engine();
			$current_plan_id = (int) $engine->get_user_active_membership_plan_id( $user_id );
		}

		// Build plan data array for the view.
		$plans_data = [];
		foreach ( $plans as $plan ) {
			$rules         = get_post_meta( $plan->ID, '_clisyc_member_plan_access_rules', true );
			$rules         = is_array( $rules ) ? $rules : [];
			$price_id      = get_post_meta( $plan->ID, Constants::META_LINKED_STRIPE_PRICE_ID, true );
			$trial_days    = (int) get_post_meta( $plan->ID, Constants::META_STRIPE_TRIAL_DAYS, true );
			$price_display = get_post_meta( $plan->ID, '_clisyc_plan_price_display', true );
			$description   = get_post_meta( $plan->ID, '_clisyc_plan_description', true );

			// Parse rules into human-readable benefits.
			$benefits = $this->parse_rules_to_benefits( $rules );

			$plans_data[] = [
				'id'            => $plan->ID,
				'title'         => $plan->post_title,
				'description'   => $description ?: '',
				'price_display' => $price_display ?: '',
				'benefits'      => $benefits,
				'has_stripe'    => ! empty( $price_id ),
				'trial_days'    => $trial_days,
				'is_current'    => ( $plan->ID === $current_plan_id ),
				'nonce'         => wp_create_nonce( 'clisyc_subscribe_' . $plan->ID ),
			];
		}

		ob_start();

		$columns_class = 'clisyc-plans-grid--' . absint( $atts['columns'] );
		$highlight_id  = absint( $atts['highlight'] );
		$is_logged_in  = is_user_logged_in();
		$login_url     = wp_login_url( get_permalink() );
		$ajax_url      = admin_url( 'admin-ajax.php' );

		$view_file = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-membership-plans.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}

		return ob_get_clean();
	}

	/**
	 * Converts access rules into human-readable benefit strings.
	 *
	 * @param array $rules The access rules array.
	 * @return string[]
	 */
	private function parse_rules_to_benefits( array $rules ): array {
		$benefits = [];

		foreach ( $rules as $rule ) {
			$service_name = ( 0 === (int) ( $rule['service_id'] ?? 0 ) )
				? __( 'all services', 'client-sync' )
				: get_the_title( (int) $rule['service_id'] );

			switch ( $rule['rule_type'] ?? '' ) {
				case 'booking_limit':
					if ( 'unlimited' === ( $rule['limit_period'] ?? '' ) ) {
						/* translators: %s: service name (e.g. "all services"). */
						$benefits[] = sprintf( __( 'Unlimited bookings on %s', 'client-sync' ), $service_name );
					} else {
						$period_labels = [
							'per_week'  => __( 'per week', 'client-sync' ),
							'per_month' => __( 'per month', 'client-sync' ),
							'per_year'  => __( 'per year', 'client-sync' ),
						];
						/* translators: %1$d: number of bookings, %2$s: period label, %3$s: service name. */
						$benefits[] = sprintf(
							__( '%1$d bookings %2$s on %3$s', 'client-sync' ),
							(int) ( $rule['limit_value'] ?? 0 ),
							$period_labels[ $rule['limit_period'] ?? 'per_month' ] ?? '',
							$service_name
						);
					}
					break;

				case 'discount_percent':
					/* translators: %1$d: discount percentage, %2$s: service name. */
					$benefits[] = sprintf(
						__( '%1$d%% discount on %2$s', 'client-sync' ),
						(int) ( $rule['discount_value'] ?? 0 ),
						$service_name
					);
					break;

				case 'discount_amount':
					/* translators: %1$s: discount amount, %2$s: service name. */
					$benefits[] = sprintf(
						__( '$%1$s off %2$s', 'client-sync' ),
						number_format( (float) ( $rule['discount_value'] ?? 0 ), 2 ),
						$service_name
					);
					break;
			}
		}

		return $benefits;
	}
}
