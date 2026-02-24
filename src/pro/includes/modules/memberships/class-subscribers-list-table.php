<?php
/**
 * File: src/pro/includes/modules/memberships/class-subscribers-list-table.php
 *      -> client-sync-pro/includes/modules/memberships/class-subscribers-list-table.php
 * WP_List_Table for displaying users who have active Stripe subscriptions.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Memberships
 */

namespace ClientSyncPro\Modules\Memberships;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Subscribers_List_Table extends \WP_List_Table {

	/**
	 * Cached plan titles keyed by post ID.
	 * Pre-fetched in prepare_items() to avoid N+1 queries.
	 *
	 * @var array<int, string>
	 */
	private $plan_title_cache = [];

	/**
	 * The Stripe dashboard base URL (live or test).
	 *
	 * @var string
	 */
	private $stripe_dashboard_base = 'https://dashboard.stripe.com/';

	public function __construct() {
		parent::__construct( [
			'singular' => __( 'Subscriber', 'client-sync-pro' ),
			'plural'   => __( 'Subscribers', 'client-sync-pro' ),
			'ajax'     => false,
		] );
	}

	/**
	 * Define the table columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return [
			'cb'              => '<input type="checkbox" />',
			'subscriber'      => __( 'Subscriber', 'client-sync-pro' ),
			'plan'            => __( 'Plan', 'client-sync-pro' ),
			'status'          => __( 'Status', 'client-sync-pro' ),
			'renewal_date'    => __( 'Renewal Date', 'client-sync-pro' ),
			'subscription_id' => __( 'Subscription ID', 'client-sync-pro' ),
		];
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return [
			'subscriber'   => [ 'display_name', false ],
			'status'       => [ 'meta_status', false ],
			'renewal_date' => [ 'meta_renewal', false ],
		];
	}

	/**
	 * No bulk actions for now.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return [];
	}

	/**
	 * Prepare items for display: query, filter, sort, paginate.
	 */
	public function prepare_items() {
		$this->stripe_dashboard_base = $this->determine_stripe_dashboard_base();
		$this->_column_headers       = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$per_page = 20;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET params for filtering/sorting.
		$search        = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby       = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'display_name';
		$order         = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( $_REQUEST['order'] ) ) : 'ASC';
		$status_filter = isset( $_REQUEST['clisyc_sub_status'] ) ? sanitize_key( $_REQUEST['clisyc_sub_status'] ) : '';
		$plan_filter   = isset( $_REQUEST['clisyc_plan_id'] ) ? absint( $_REQUEST['clisyc_plan_id'] ) : 0;
		// phpcs:enable

		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			$order = 'ASC';
		}

		// Base query: all users with a Stripe subscription ID.
		$meta_query = [
			'relation' => 'AND',
			[
				'key'     => Constants::META_STRIPE_SUBSCRIPTION_ID,
				'compare' => 'EXISTS',
			],
		];

		// Status filter.
		if ( ! empty( $status_filter ) ) {
			$meta_query[] = [
				'key'   => Constants::META_STRIPE_SUB_STATUS,
				'value' => $status_filter,
			];
		}

		// Plan filter.
		if ( ! empty( $plan_filter ) ) {
			$meta_query[] = [
				'key'   => Constants::META_STRIPE_SUB_PLAN_ID,
				'value' => $plan_filter,
			];
		}

		$args = [
			'number'     => $per_page,
			'paged'      => $this->get_pagenum(),
			'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		];

		// Handle search.
		if ( ! empty( $search ) ) {
			if ( 0 === strpos( $search, 'sub_' ) ) {
				// Search by subscription ID.
				$args['meta_query'][] = [
					'key'     => Constants::META_STRIPE_SUBSCRIPTION_ID,
					'value'   => $search,
					'compare' => 'LIKE',
				];
			} else {
				$args['search']         = '*' . $search . '*';
				$args['search_columns'] = [ 'user_email', 'user_login', 'display_name' ];
			}
		}

		// Handle sorting.
		if ( 'meta_status' === $orderby ) {
			$args['meta_query']['status_clause'] = [
				'key'     => Constants::META_STRIPE_SUB_STATUS,
				'compare' => 'EXISTS',
			];
			$args['orderby'] = 'status_clause';
			$args['order']   = $order;
		} elseif ( 'meta_renewal' === $orderby ) {
			$args['meta_query']['renewal_clause'] = [
				'key'     => Constants::META_SUBSCRIPTION_PERIOD_END,
				'type'    => 'NUMERIC',
				'compare' => 'EXISTS',
			];
			$args['orderby'] = 'renewal_clause';
			$args['order']   = $order;
		} else {
			$args['orderby'] = 'display_name';
			$args['order']   = $order;
		}

		$user_query  = new \WP_User_Query( $args );
		$this->items = $user_query->get_results();

		// Pre-cache plan titles.
		$plan_ids = [];
		foreach ( $this->items as $user ) {
			$pid = (int) get_user_meta( $user->ID, Constants::META_STRIPE_SUB_PLAN_ID, true );
			if ( $pid > 0 ) {
				$plan_ids[] = $pid;
			}
		}
		$plan_ids = array_unique( $plan_ids );
		if ( ! empty( $plan_ids ) ) {
			$plans = get_posts( [
				'post__in'       => $plan_ids,
				'post_type'      => 'clisyc_member_plan',
				'posts_per_page' => count( $plan_ids ),
				'post_status'    => 'any',
			] );
			foreach ( $plans as $plan ) {
				$this->plan_title_cache[ $plan->ID ] = $plan->post_title;
			}
		}

		$this->set_pagination_args( [
			'total_items' => $user_query->get_total(),
			'per_page'    => $per_page,
		] );
	}

	/**
	 * Render the filter dropdowns above the table.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current_status = isset( $_REQUEST['clisyc_sub_status'] ) ? sanitize_key( $_REQUEST['clisyc_sub_status'] ) : '';
		$current_plan   = isset( $_REQUEST['clisyc_plan_id'] ) ? absint( $_REQUEST['clisyc_plan_id'] ) : 0;
		// phpcs:enable

		$statuses = [
			''         => __( 'All Statuses', 'client-sync-pro' ),
			'active'   => __( 'Active', 'client-sync-pro' ),
			'trialing' => __( 'Trialing', 'client-sync-pro' ),
			'past_due' => __( 'Past Due', 'client-sync-pro' ),
			'canceled' => __( 'Canceled', 'client-sync-pro' ),
		];

		echo '<div class="alignleft actions">';

		// Status dropdown.
		echo '<select name="clisyc_sub_status">';
		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current_status, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		// Plan dropdown.
		$plans = get_posts( [
			'post_type'      => 'clisyc_member_plan',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		echo '<select name="clisyc_plan_id">';
		echo '<option value="">' . esc_html__( 'All Plans', 'client-sync-pro' ) . '</option>';
		foreach ( $plans as $plan ) {
			printf(
				'<option value="%d" %s>%s</option>',
				$plan->ID,
				selected( $current_plan, $plan->ID, false ),
				esc_html( $plan->post_title )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'client-sync-pro' ), '', 'filter_action', false );

		// Clear filters link.
		if ( ! empty( $current_status ) || ! empty( $current_plan ) ) {
			printf(
				' <a href="%s" class="button">%s</a>',
				esc_url( admin_url( 'admin.php?page=clisyc-subscribers' ) ),
				esc_html__( 'Clear Filters', 'client-sync-pro' )
			);
		}

		echo '</div>';
	}

	// ------------------------------------------------------------------
	// Column renderers
	// ------------------------------------------------------------------

	/**
	 * Checkbox column.
	 *
	 * @param \WP_User $item Current user object.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="users[]" value="%s" />', $item->ID );
	}

	/**
	 * Subscriber column: display name, email, and row actions.
	 *
	 * @param \WP_User $item Current user object.
	 * @return string
	 */
	protected function column_subscriber( $item ) {
		$name  = esc_html( $item->display_name );
		$email = esc_html( $item->user_email );

		$output = "<strong>{$name}</strong><br><small>{$email}</small>";

		$actions = [
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_edit_user_link( $item->ID ) ),
				__( 'Edit User', 'client-sync-pro' )
			),
		];

		$customer_id = get_user_meta( $item->ID, Constants::META_STRIPE_CUSTOMER_ID, true );
		if ( ! empty( $customer_id ) ) {
			$actions['stripe'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s &#x2197;</a>',
				esc_url( $this->stripe_dashboard_base . 'customers/' . $customer_id ),
				__( 'View in Stripe', 'client-sync-pro' )
			);
		}

		return $output . $this->row_actions( $actions );
	}

	/**
	 * Plan column: plan title linked to edit screen.
	 *
	 * @param \WP_User $item Current user object.
	 * @return string
	 */
	protected function column_plan( $item ) {
		$plan_id = (int) get_user_meta( $item->ID, Constants::META_STRIPE_SUB_PLAN_ID, true );
		if ( ! $plan_id ) {
			return '&mdash;';
		}

		$title     = $this->plan_title_cache[ $plan_id ] ?? __( 'Unknown Plan', 'client-sync-pro' );
		$edit_link = get_edit_post_link( $plan_id );

		if ( $edit_link ) {
			return sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( $title ) );
		}

		return esc_html( $title );
	}

	/**
	 * Status column: colored badge.
	 *
	 * @param \WP_User $item Current user object.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status = get_user_meta( $item->ID, Constants::META_STRIPE_SUB_STATUS, true );
		if ( empty( $status ) ) {
			return '&mdash;';
		}

		$labels = [
			'active'   => __( 'Active', 'client-sync-pro' ),
			'trialing' => __( 'Trialing', 'client-sync-pro' ),
			'past_due' => __( 'Past Due', 'client-sync-pro' ),
			'canceled' => __( 'Canceled', 'client-sync-pro' ),
		];

		$label    = $labels[ $status ] ?? ucfirst( $status );
		$modifier = sanitize_html_class( $status );

		return sprintf(
			'<span class="clisyc-sub-status clisyc-sub-status--%s">%s</span>',
			esc_attr( $modifier ),
			esc_html( $label )
		);
	}

	/**
	 * Renewal date column.
	 *
	 * @param \WP_User $item Current user object.
	 * @return string
	 */
	protected function column_renewal_date( $item ) {
		$period_end = (int) get_user_meta( $item->ID, Constants::META_SUBSCRIPTION_PERIOD_END, true );
		if ( ! $period_end ) {
			return '&mdash;';
		}
		return esc_html( wp_date( get_option( 'date_format' ), $period_end ) );
	}

	/**
	 * Subscription ID column: Stripe sub ID linked to Stripe dashboard.
	 *
	 * @param \WP_User $item Current user object.
	 * @return string
	 */
	protected function column_subscription_id( $item ) {
		$sub_id = get_user_meta( $item->ID, Constants::META_STRIPE_SUBSCRIPTION_ID, true );
		if ( empty( $sub_id ) ) {
			return '&mdash;';
		}
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer"><code>%s</code></a>',
			esc_url( $this->stripe_dashboard_base . 'subscriptions/' . $sub_id ),
			esc_html( $sub_id )
		);
	}

	/**
	 * Default column renderer (fallback).
	 *
	 * @param \WP_User $item        Current user object.
	 * @param string   $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '&mdash;';
	}

	/**
	 * Message displayed when there are no subscribers.
	 */
	public function no_items() {
		echo '<div class="clisyc-empty-state">';
		echo '<span class="dashicons dashicons-groups"></span>';
		echo '<p class="clisyc-empty-state__title">';
		esc_html_e( 'No subscribers found.', 'client-sync-pro' );
		echo '</p>';
		echo '<p class="clisyc-empty-state__desc">';
		esc_html_e( 'Subscribers will appear here once users sign up for a membership plan through Stripe.', 'client-sync-pro' );
		echo '</p>';
		echo '</div>';
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Determine the Stripe dashboard base URL (test or live).
	 *
	 * @return string
	 */
	private function determine_stripe_dashboard_base(): string {
		$secret_key = get_option( Constants::OPTION_STRIPE_KEY_SECRET, '' );
		if ( ! empty( $secret_key ) && 0 === strpos( $secret_key, 'sk_test_' ) ) {
			return 'https://dashboard.stripe.com/test/';
		}
		return 'https://dashboard.stripe.com/';
	}
}
