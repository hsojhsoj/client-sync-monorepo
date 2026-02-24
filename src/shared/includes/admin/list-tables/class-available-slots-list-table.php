<?php
/**
 * File: src/shared/includes/admin/list-tables/class-available-slots-list-table.php -> client-sync/includes/admin/list-tables/class-available-slots-list-table.php
 * Defines the WP_List_Table for displaying available and blocked time slots.
 * Now includes a consolidated "Details" column for dimensions and uses 'clisyc_' prefixing.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/ListTables
 */

namespace DependentMedia\ClientSync\Admin\ListTables;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Available_Slots_List_Table extends \WP_List_Table {

	/**
	 * Cached labels for dimension CPTs.
	 *
	 * @var array e.g., ['clisyc_service' => 'Services', 'clisyc_practitioner' => 'Engineers']
	 */
	private $dimension_labels = [];

	/**
	 * Cached titles for all post IDs displayed on the current page.
	 *
	 * @var array e.g., [101 => 'Full Band Tracking', 201 => 'Alice (Senior Engineer)']
	 */
	private $post_title_cache = [];

	/**
	 * Keys of dimensions used for availability filtering.
	 *
	 * @var array
	 */
	private $availability_dimension_keys = [];

	/**
	 * Definitions of all appointment custom fields.
	 *
	 * @var array
	 */
	private $appointment_custom_fields = [];

	/**
	 * Cache of booked slot times (UTC datetime strings) to appointment data.
	 * Used to determine if a slot is booked and link to the appointment.
	 *
	 * @var array e.g., ['2025-12-11 22:00:00' => ['id' => 123, 'title' => 'Appointment Name']]
	 */
	private $booked_slots_cache = [];


	public function __construct() {
		parent::__construct(
			[
				'singular' => __( 'Available Slot', 'client-sync' ),
				'plural'   => __( 'Available Slots', 'client-sync' ),
				'ajax'     => false,
			]
		);

		$registry             = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$custom_types         = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		$enabled_dimensions   = $registry['dimensions'] ?? [];
		$all_dimension_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );

		foreach ( $enabled_dimensions as $slug => $settings ) {
			if ( ! empty( $settings['enabled'] ) ) {
				if ( isset( $custom_types[ $slug ]['plural'] ) ) {
					$this->dimension_labels[ $slug ] = $custom_types[ $slug ]['plural'];
				} else {
					$cpt_object                      = get_post_type_object( $slug );
					$this->dimension_labels[ $slug ] = $cpt_object ? $cpt_object->labels->name : $slug;
				}
				// Populate legacy properties for extra_tablenav dropdowns
				if ( isset( $all_dimension_fields[ $slug ] ) ) {
					$this->availability_dimension_keys[]      = $slug;
					$this->appointment_custom_fields[ $slug ] = $all_dimension_fields[ $slug ];
				}
			}
		}
	}

	/**
	 * Prepare the items for the table to process.
	 */
	public function prepare_items() {
		global $wpdb;

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		// --- START FIX: Get User Preference for Pagination ---
		$user_id = get_current_user_id();
		$screen  = get_current_screen();
		$option  = ( $screen ) ? $screen->get_option( 'per_page', 'option' ) : 'clisyc_slots_per_page';

		$per_page = get_user_meta( $user_id, $option, true );

		if ( empty( $per_page ) || $per_page < 1 ) {
			$per_page = ( $screen ) ? $screen->get_option( 'per_page', 'default' ) : 20;
		}

		// Ensure strictly integer for calculations
		$per_page = (int) $per_page;
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		// --- END FIX ---

		$current_page         = $this->get_pagenum();
		$offset               = ( $current_page - 1 ) * $per_page;

		// Sanitize individual request variables.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby_request = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'start_time';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_request   = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$start_date_raw  = isset( $_REQUEST['clisyc_filter_date_start'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['clisyc_filter_date_start'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$end_date_raw    = isset( $_REQUEST['clisyc_filter_date_end'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['clisyc_filter_date_end'] ) ) : '';
		
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$selected_dimensions = isset( $_REQUEST['dimensions'] ) && is_array( $_REQUEST['dimensions'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? array_filter( array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['dimensions'] ) ) )
			: [];

		$slots_table = $wpdb->prefix . 'clisyc_time_slots';
		$dims_table  = $wpdb->prefix . 'clisyc_slot_dimensions';

		$where_clauses = [];
		$params        = [];

		// Date Range Filtering
		$site_timezone = wp_timezone();
		$utc_timezone  = new \DateTimeZone( 'UTC' );

		// Track date range for booking lookup
		$query_start_utc = null;
		$query_end_utc   = null;

		if ( ! empty( $start_date_raw ) ) {
			try {
				$start_date_local = new \DateTime( $start_date_raw . ' 00:00:00', $site_timezone );
				$utc_start_string = $start_date_local->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
				$where_clauses[]  = 's.start_time >= %s';
				$params[]         = $utc_start_string;
				$query_start_utc  = $utc_start_string;
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.Detected
				// Invalid date format, ignore filter.
			}
		}

		if ( ! empty( $end_date_raw ) ) {
			try {
				$end_date_local = new \DateTime( $end_date_raw . ' 23:59:59', $site_timezone );
				$utc_end_string = $end_date_local->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
				$where_clauses[] = 's.start_time <= %s';
				$params[]        = $utc_end_string;
				$query_end_utc   = $utc_end_string;
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.Detected
				// Invalid date format, ignore filter.
			}
		}

		// Dimension Filtering
		if ( ! empty( $selected_dimensions ) ) {
			$dim_conditions = [];
			$dim_params     = [];
			foreach ( $selected_dimensions as $key => $value ) {
				$dim_conditions[] = '(d.dimension_key = %s AND d.dimension_value = %s)';
				$dim_params[]     = sanitize_key( $key );
				$dim_params[]     = $value;
			}
			$num_dims = count( $selected_dimensions );

			$where_clauses[] = "s.slot_id IN (
                SELECT d_filter.slot_id FROM {$dims_table} d_filter
                WHERE " . implode( ' OR ', $dim_conditions ) . '
                GROUP BY d_filter.slot_id
                HAVING COUNT(DISTINCT d_filter.dimension_key) = %d
            )';
			$params          = array_merge( $params, $dim_params, [ $num_dims ] );
		}

		$where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';
		$order     = ( 'ASC' === $order_request ) ? 'ASC' : 'DESC';

		$valid_orderby = [ 'start_time', 'is_block' ];
		$orderby_col   = in_array( $orderby_request, $valid_orderby, true ) ? 's.' . $orderby_request : 's.start_time';

		// The dynamic parts are interpolated before prepare, but they are sanitized and from internal sources, not user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$main_query = "SELECT SQL_CALC_FOUND_ROWS s.slot_id, s.start_time, s.end_time, s.is_block, s.booking_count,
            GROUP_CONCAT(CONCAT(d.dimension_key, ':::', d.dimension_value) SEPARATOR '|||') AS dimensions_concat
            FROM {$slots_table} s
            LEFT JOIN {$dims_table} d ON s.slot_id = d.slot_id
            {$where_sql}
            GROUP BY s.slot_id
            ORDER BY {$orderby_col} {$order}
            LIMIT %d OFFSET %d";

		$final_params = array_merge( $params, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare( $main_query, ...$final_params ), ARRAY_A );
		$this->items  = $this->parse_db_results( $results );

		// Efficiently pre-cache all needed post titles
		$post_ids_to_fetch = [];
		foreach ( $this->items as $item ) {
			if ( ! empty( $item['dimensions'] ) && is_array( $item['dimensions'] ) ) {
				foreach ( $item['dimensions'] as $value ) {
					if ( is_numeric( $value ) ) {
						$post_ids_to_fetch[] = (int) $value;
					}
				}
			}
		}

		if ( ! empty( $post_ids_to_fetch ) ) {
			$post_ids_to_fetch = array_unique( $post_ids_to_fetch );
			$posts             = get_posts(
				[
					'post__in'            => $post_ids_to_fetch,
					'post_type'           => array_keys( $this->dimension_labels ),
					'posts_per_page'      => -1,
					'ignore_sticky_posts' => 1,
				]
			);
			foreach ( $posts as $post ) {
				$this->post_title_cache[ $post->ID ] = $post->post_title;
			}
		}

		// --- FIX: Pre-cache booked slots from appointment post meta ---
		$this->cache_booked_slots( $query_start_utc, $query_end_utc );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is for pagination counting.
		$total_items = $wpdb->get_var( 'SELECT FOUND_ROWS()' );

		$this->set_pagination_args(
			[
				'total_items' => (int) $total_items,
				'per_page'    => $per_page,
			]
		);
	}

	/**
	 * Cache booked slots by querying appointment post meta.
	 * This allows us to determine if a slot is booked without relying on the bookings table.
	 *
	 * @param string|null $start_utc Start date in UTC (Y-m-d H:i:s format) or null for no limit.
	 * @param string|null $end_utc End date in UTC (Y-m-d H:i:s format) or null for no limit.
	 */
	private function cache_booked_slots( $start_utc = null, $end_utc = null ) {
		global $wpdb;

		$this->booked_slots_cache = [];

		// Build meta query to get appointments
		$meta_query = [
			'relation' => 'AND',
			[
				'key'     => Constants::META_TIME_SLOT,
				'compare' => 'EXISTS',
			],
		];

		// Add date range filters if provided
		if ( $start_utc ) {
			$meta_query[] = [
				'key'     => Constants::META_TIME_SLOT,
				'value'   => $start_utc,
				'compare' => '>=',
				'type'    => 'DATETIME',
			];
		}

		if ( $end_utc ) {
			$meta_query[] = [
				'key'     => Constants::META_TIME_SLOT,
				'value'   => $end_utc,
				'compare' => '<=',
				'type'    => 'DATETIME',
			];
		}

		// If no date range, get all appointments (reasonable for admin list)
		// But limit to avoid performance issues
		$appointments = get_posts( [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => [ 'publish', 'pending', 'confirmed', 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ],
			'posts_per_page' => 500, // Reasonable limit
			'meta_query'     => $meta_query,
		] );

		foreach ( $appointments as $appointment ) {
			$time_slot = get_post_meta( $appointment->ID, Constants::META_TIME_SLOT, true );
			if ( $time_slot ) {
				$this->booked_slots_cache[ $time_slot ] = [
					'id'    => $appointment->ID,
					'title' => $appointment->post_title,
				];
			}
		}
	}

	/**
	 * Check if a slot is booked by looking up its start time in the cache.
	 *
	 * @param string $start_time_utc The slot's start time in UTC (Y-m-d H:i:s format).
	 * @return array|false Appointment data if booked, false otherwise.
	 */
	private function is_slot_booked( $start_time_utc ) {
		return $this->booked_slots_cache[ $start_time_utc ] ?? false;
	}


	/**
	 * Parses raw DB results into the format expected by the table's column renderers.
	 *
	 * @param array $results Raw results from $wpdb->get_results.
	 * @return array Parsed items.
	 */
	private function parse_db_results( array $results ): array {
		$parsed_items  = [];
		$site_timezone = wp_timezone();
		$utc_timezone  = new \DateTimeZone( 'UTC' );

		foreach ( $results as $row ) {
			$dimensions_array = [];
			if ( ! empty( $row['dimensions_concat'] ) ) {
				$pairs = explode( '|||', $row['dimensions_concat'] );
				foreach ( $pairs as $pair ) {
					$parts = explode( ':::', $pair, 2 );
					if ( count( $parts ) === 2 ) {
						list( $key, $value )      = $parts;
						$dimensions_array[ $key ] = $value;
					}
				}
			}

			// Convert UTC time to local time for display
			$local_start = '';
			$local_end   = '';
			$local_date  = '';
			$start_time_utc = $row['start_time'] ?? '';

			if ( ! empty( $row['start_time'] ) ) {
				try {
					$start_dt    = new \DateTime( $row['start_time'], $utc_timezone );
					$start_dt->setTimezone( $site_timezone );
					$local_date  = $start_dt->format( 'Y-m-d' );
					$local_start = $start_dt->format( 'H:i:s' );
				} catch ( \Exception $e ) {
					$local_start = $row['start_time'];
				}
			}

			if ( ! empty( $row['end_time'] ) ) {
				try {
					$end_dt    = new \DateTime( $row['end_time'], $utc_timezone );
					$end_dt->setTimezone( $site_timezone );
					$local_end = $end_dt->format( 'H:i:s' );
				} catch ( \Exception $e ) {
					$local_end = $row['end_time'];
				}
			}

			$parsed_items[] = [
				'slot_id'        => $row['slot_id'],
				'date'           => $local_date,
				'start_time'     => $local_start,
				'end_time'       => $local_end,
				'start_time_utc' => $start_time_utc, // Keep UTC for booking lookups and links
				'is_block'       => (bool) ( $row['is_block'] ?? false ),
				'booking_count'  => (int) ( $row['booking_count'] ?? 0 ),
				'dimensions'     => $dimensions_array,
			];
		}

		return $parsed_items;
	}


	/**
	 * Extra navigation controls above/below the table (filters).
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_dimensions = isset( $_REQUEST['dimensions'] ) && is_array( $_REQUEST['dimensions'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? array_filter( array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['dimensions'] ) ) )
			: [];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$start_date_raw = isset( $_REQUEST['clisyc_filter_date_start'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['clisyc_filter_date_start'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$end_date_raw   = isset( $_REQUEST['clisyc_filter_date_end'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['clisyc_filter_date_end'] ) ) : '';

		?>
		<div class="alignleft actions">
			<label for="clisyc_filter_date_start" class="screen-reader-text"><?php esc_html_e( 'Start Date', 'client-sync' ); ?></label>
			<input type="date" name="clisyc_filter_date_start" id="clisyc_filter_date_start" value="<?php echo esc_attr( $start_date_raw ); ?>" placeholder="<?php esc_attr_e( 'Start Date', 'client-sync' ); ?>">

			<label for="clisyc_filter_date_end" class="screen-reader-text"><?php esc_html_e( 'End Date', 'client-sync' ); ?></label>
			<input type="date" name="clisyc_filter_date_end" id="clisyc_filter_date_end" value="<?php echo esc_attr( $end_date_raw ); ?>" placeholder="<?php esc_attr_e( 'End Date', 'client-sync' ); ?>">

			<?php
			// Dimension filter dropdowns
			foreach ( $this->availability_dimension_keys as $dimension_key ) {
				$field_config = $this->appointment_custom_fields[ $dimension_key ] ?? null;
				if ( ! $field_config ) {
					continue;
				}

				$label     = $this->dimension_labels[ $dimension_key ] ?? $dimension_key;
				$post_type = $field_config['post_type'] ?? $dimension_key;
				$selected  = $selected_dimensions[ $dimension_key ] ?? '';

				$posts = get_posts( [
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'post_status'    => 'publish',
				] );

				if ( ! empty( $posts ) ) {
					?>
					<select name="dimensions[<?php echo esc_attr( $dimension_key ); ?>]">
						<option value="">
							<?php
							/* translators: %s: The plural label of a dimension (e.g., "Services" or "Staff"). */
							echo esc_html( sprintf( __( 'All %s', 'client-sync' ), $label ) );
							?>
						</option>
						<?php foreach ( $posts as $p ) : ?>
							<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $selected, $p->ID ); ?>>
								<?php echo esc_html( $p->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php
				}
			}
			?>

			<?php submit_button( __( 'Filter', 'client-sync' ), '', 'filter_action', false ); ?>

			<?php
			// Show "Clear Filters" link if any filters are active
			if ( ! empty( $start_date_raw ) || ! empty( $end_date_raw ) || ! empty( $selected_dimensions ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
				$clear_url = admin_url( 'admin.php?page=' . $page );
				echo '<a href="' . esc_url( $clear_url ) . '" class="button">' . esc_html__( 'Clear Filters', 'client-sync' ) . '</a>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Get a list of the table's columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return [
			'cb'            => '<input type="checkbox" />',
			'date'          => __( 'Date', 'client-sync' ),
			'start_time'    => __( 'Start Time', 'client-sync' ),
			'end_time'      => __( 'End Time', 'client-sync' ),
			'slot_type'     => __( 'Type', 'client-sync' ),
			'booking_count' => __( 'Booked', 'client-sync' ),
			'dimensions'    => __( 'Details', 'client-sync' ),
		];
	}

	/**
	 * Get a list of sortable columns.
	 */
	protected function get_sortable_columns() {
		return [
			'date'       => [ 'start_time', false ], // Sorting by date is the same as sorting by start_time
			'start_time' => [ 'start_time', false ],
			'slot_type'  => [ 'is_block', false ],
		];
	}

	/**
	 * Get the bulk actions available for this table.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return [ 'bulk-delete' => __( 'Delete', 'client-sync' ) ];
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param array $item The current row item.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="selected_slots[]" value="%s" />',
			esc_attr( $item['slot_id'] )
		);
	}

	/**
	 * Renders the 'Start Time' column.
	 *
	 * @param array $item The current row item.
	 * @return string
	 */
	protected function column_start_time( $item ) {
		$date_str = $item['date'] ?? null;
		$time_str = $item['start_time'] ?? null;

		if ( ! $date_str || ! $time_str ) {
			return '—';
		}

		try {
			$local_datetime_str = $date_str . ' ' . $time_str;
			$site_timezone      = wp_timezone();
			$local_dt_obj       = new \DateTime( $local_datetime_str, $site_timezone );
			$timestamp          = $local_dt_obj->getTimestamp();
			return esc_html( wp_date( get_option( 'time_format' ), $timestamp ) );
		} catch ( \Exception $e ) {
			return esc_html( $time_str );
		}
	}

	/**
	 * Renders the 'End Time' column.
	 *
	 * @param array $item The current row item.
	 * @return string
	 */
	protected function column_end_time( $item ) {
		$date_str = $item['date'] ?? null;
		$time_str = $item['end_time'] ?? null;

		if ( ! $date_str || ! $time_str ) {
			return '—';
		}

		try {
			$local_datetime_str = $date_str . ' ' . $time_str;
			$site_timezone      = wp_timezone();
			$local_dt_obj       = new \DateTime( $local_datetime_str, $site_timezone );
			$timestamp          = $local_dt_obj->getTimestamp();
			return esc_html( wp_date( get_option( 'time_format' ), $timestamp ) );
		} catch ( \Exception $e ) {
			return esc_html( $time_str );
		}
	}

	/**
	 * Renders the 'Type' column.
	 * Now checks for booked appointments via post meta lookup.
	 *
	 * @param array $item The current row item.
	 * @return string
	 */
	protected function column_slot_type( $item ) {
		// Check if blocked first
		if ( isset( $item['is_block'] ) && $item['is_block'] ) {
			return '<span style="color:#d63638;">' . esc_html__( 'Blocked', 'client-sync' ) . '</span>';
		}

		// Check if booked by looking up appointment post meta
		$start_time_utc = $item['start_time_utc'] ?? '';
		$booking_info = $this->is_slot_booked( $start_time_utc );

		if ( $booking_info ) {
			// Slot is booked - link to the appointment
			$edit_link = get_edit_post_link( $booking_info['id'] );
			if ( $edit_link ) {
				return '<span style="color:#00a32a;"><a href="' . esc_url( $edit_link ) . '" title="' . esc_attr( $booking_info['title'] ) . '">' . esc_html__( 'Booked', 'client-sync' ) . '</a></span>';
			}
			return '<span style="color:#00a32a;">' . esc_html__( 'Booked', 'client-sync' ) . '</span>';
		}

		// Default: Available
		return '<span style="color:#2271b1;">' . esc_html__( 'Available', 'client-sync' ) . '</span>';
	}

	/**
	 * Renders the 'Booked Count' column.
	 *
	 * @param array $item The current row item.
	 * @return string
	 */
	protected function column_booking_count( $item ) {
		if ( ! empty( $item['is_block'] ) ) {
			return '<span style="color:#ccc;">—</span>';
		}

		// Check via our cache for accurate count
		$start_time_utc = $item['start_time_utc'] ?? '';
		$booking_info = $this->is_slot_booked( $start_time_utc );

		if ( $booking_info ) {
			$edit_link = get_edit_post_link( $booking_info['id'] );
			if ( $edit_link ) {
				return '<strong><a href="' . esc_url( $edit_link ) . '">1</a></strong>';
			}
			return '<strong>1</strong>';
		}

		return '<span style="color:#999;">0</span>';
	}

	/**
	 * Renders the content for our new 'dimensions' column.
	 *
	 * @param array $item The current row item.
	 * @return string
	 */
	public function column_dimensions( $item ) {
		if ( empty( $item['dimensions'] ) || ! is_array( $item['dimensions'] ) ) {
			return '—';
		}

		$output = '<ul class="clisyc-list-table-dimensions">';
		foreach ( $item['dimensions'] as $key => $value ) {
			$label = $this->dimension_labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
			$title = $this->post_title_cache[ $value ] ?? $value; // Fallback to the ID if title not found

			$output .= '<li><strong>' . esc_html( rtrim( $label, 's' ) ) . ':</strong> ' . esc_html( $title ) . '</li>';
		}
		$output .= '</ul>';

		return wp_kses(
			$output,
			[
				'ul'     => [ 'class' => [] ],
				'li'     => [],
				'strong' => [],
			]
		);
	}

	/**
	 * Default column handler.
	 *
	 * @param array  $item The current row item.
	 * @param string $column_name The name of the column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return '—';
	}

	/**
	 * Handles the 'date' column with row actions.
	 *
	 * @param array $item The current row item.
	 * @return string
	 */
	protected function column_date( $item ) {
		$date   = $item['date'] ?? '';
		$output = '—';

		if ( $date ) {
			try {
				$site_timezone = wp_timezone();
				$dt_obj        = new \DateTime( $date . ' 00:00:00', $site_timezone );
				$timestamp     = $dt_obj->getTimestamp();
				$output        = esc_html( wp_date( get_option( 'date_format' ), $timestamp ) );
			} catch ( \Exception $e ) {
				$output = esc_html( $date );
			}
		}

		$actions = [];

		// Check if slot is booked
		$start_time_utc = $item['start_time_utc'] ?? '';
		$booking_info = $this->is_slot_booked( $start_time_utc );

		if ( $booking_info ) {
			// Slot is booked - show "View Appointment" link instead of "Create Appointment"
			$edit_link = get_edit_post_link( $booking_info['id'] );
			if ( $edit_link ) {
				$actions['view_appointment'] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( $edit_link ),
					esc_html__( 'View Appointment', 'client-sync' )
				);
			}
		} elseif ( ! $item['is_block'] ) {
			// Only show the "Create Appointment" link for available (not blocked, not booked) slots
			$create_appt_url_args = [
				'post_type'   => Constants::POST_TYPE_APPOINTMENT,
				'clisyc_slot' => $item['start_time_utc'],
			];

			// Pass all of the slot's dimensions as query parameters
			if ( ! empty( $item['dimensions'] ) && is_array( $item['dimensions'] ) ) {
				foreach ( $item['dimensions'] as $key => $value ) {
					$create_appt_url_args[ 'clisyc_dim_' . $key ] = $value;
				}
			}

			$create_appt_url = add_query_arg( $create_appt_url_args, admin_url( 'post-new.php' ) );

			$actions['create_appointment'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $create_appt_url ),
				esc_html__( 'Create Appointment', 'client-sync' )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';

		$delete_url = add_query_arg(
			[
				'page'     => $page,
				'action'   => 'clisyc_single_delete',
				'slot_id'  => $item['slot_id'],
				'_wpnonce' => wp_create_nonce( 'clisyc_delete_slot_' . $item['slot_id'] ),
			],
			admin_url( 'admin.php' )
		);

		$actions['delete'] = sprintf(
			'<a href="%s" class="delete-tag" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $delete_url ),
			esc_attr__( 'Are you sure you want to delete this slot?', 'client-sync' ),
			__( 'Delete', 'client-sync' )
		);

		return $output . $this->row_actions( $actions );
	}

	/**
	 * Message to display when no items are found.
	 */
	public function no_items() {
		esc_html_e( 'No future available slots or blocked time slots found in the database.', 'client-sync' );
	}
}