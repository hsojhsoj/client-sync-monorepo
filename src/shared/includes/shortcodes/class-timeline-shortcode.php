<?php
/**
 * File: class-timeline-shortcode.php
 * Shortcode handler for the Timeline Visualization
 * Location: src/shared/includes/shortcodes/class-timeline-shortcode.php
 *
 * UPDATED: Added localization strings for zoom controls and resize handle
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Timeline_Shortcode {

	const SHORTCODE_TAG = 'clisyc_timeline';

	public function __construct() {
		add_shortcode( self::SHORTCODE_TAG, [ $this, 'render_shortcode' ] );
		add_shortcode( 'clisyc_timeline_view', [ $this, 'render_shortcode' ] );
	}

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			[
				'view'           => 'week',
				'resources'      => 'clisyc_service,clisyc_practitioner,clisyc_room',
				'height'         => '700',
				'show_controls'  => 'true',
				'start_time'     => '08:00',
				'end_time'       => '18:00',
				'color_by'       => 'service',
				'show_legend'    => 'true',
				'allow_booking'  => 'false',
			],
			$atts,
			self::SHORTCODE_TAG
		);

		$this->enqueue_assets();

		$can_edit = current_user_can( 'edit_posts' );
		$localized_data = $this->prepare_localized_data( $atts, $can_edit );
		$container_id = 'clisyc-timeline-' . wp_rand();

		wp_localize_script(
			'clisyc-timeline-viewer',
			'clisycTimelineData_' . str_replace( '-', '_', $container_id ),
			$localized_data
		);

		ob_start();
		?>
		<div 
			id="<?php echo esc_attr( $container_id ); ?>" 
			class="clisyc-timeline-container"
			data-config-id="<?php echo esc_attr( str_replace( '-', '_', $container_id ) ); ?>"
			style="min-height: <?php echo esc_attr( $atts['height'] ); ?>px;"
		>
			<div class="clisyc-timeline-loading">
				<div class="clisyc-timeline-spinner"></div>
				<p><?php esc_html_e( 'Loading timeline...', 'client-sync' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function enqueue_assets() {
		$asset_file = clisyc_PLUGIN_DIR . 'assets/dist/timeline-viewer/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$asset_data = include $asset_file;

			$dependencies = $asset_data['dependencies'] ?? [ 'wp-element', 'wp-api-fetch' ];

			// Register the shared FullCalendar vendor chunk (webpack code-split).
			$vendor_fc_path = clisyc_PLUGIN_DIR . 'assets/dist/vendor-fullcalendar/index.asset.php';
			if ( file_exists( $vendor_fc_path ) ) {
				$vendor_fc = include $vendor_fc_path;
				wp_register_script(
					'clisyc-vendor-fullcalendar',
					clisyc_PLUGIN_URL . 'assets/dist/vendor-fullcalendar/index.js',
					$vendor_fc['dependencies'] ?? [],
					$vendor_fc['version'] ?? null,
					true
				);
				$dependencies[] = 'clisyc-vendor-fullcalendar';
			}

			wp_enqueue_script(
				'clisyc-timeline-viewer',
				clisyc_PLUGIN_URL . 'assets/dist/timeline-viewer/index.js',
				array_values( $dependencies ),
				$asset_data['version'] ?? clisyc_VERSION,
				true
			);
		}

		wp_enqueue_style(
			'clisyc-timeline-style',
			clisyc_PLUGIN_URL . 'assets/css/clisyc-timeline.css',
			[],
			clisyc_VERSION
		);

		if ( file_exists( clisyc_PLUGIN_DIR . 'assets/vendor/fontawesome/css/all.min.css' ) ) {
			wp_enqueue_style(
				'clisyc-fontawesome',
				clisyc_PLUGIN_URL . 'assets/vendor/fontawesome/css/all.min.css',
				[],
				clisyc_VERSION
			);
		}

		wp_enqueue_style( 'dashicons' );
	}

	private function prepare_localized_data( $atts, $can_edit ) {
		$site_tz = wp_timezone();
		$resource_types = array_map( 'trim', explode( ',', $atts['resources'] ) );
		$resources = $this->get_available_resources( $resource_types );

		$saved_colors   = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$color_settings = wp_parse_args( $saved_colors, Constants::COLOR_DEFAULTS );

		return [
			'restUrl'        => 'clisyc/v1',
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'adminUrl'       => admin_url(),
			'ajaxNonce'      => wp_create_nonce( 'clisyc_timeline_nonce' ),
			'canEdit'        => $can_edit,
			'timezone'       => $site_tz->getName(),
			'dateFormat'     => get_option( 'date_format', 'F j, Y' ),
			'timeFormat'     => get_option( 'time_format', 'g:i a' ),
			'startOfWeek'    => (int) get_option( 'start_of_week', 0 ),
			'resources'      => $resources,
			'resourceTypes'  => $resource_types,
			'colorSettings'  => $color_settings,
			'colorBy'        => $atts['color_by'],
			'viewMode'       => $atts['view'],
			'height'         => $atts['height'],
			'startTime'      => $atts['start_time'],
			'endTime'        => $atts['end_time'],
			'showControls'   => filter_var( $atts['show_controls'], FILTER_VALIDATE_BOOLEAN ),
			'showLegend'     => filter_var( $atts['show_legend'], FILTER_VALIDATE_BOOLEAN ),
			'allowBooking'   => filter_var( $atts['allow_booking'], FILTER_VALIDATE_BOOLEAN ),
			'l10n'           => [
				'loading'            => __( 'Loading...', 'client-sync' ),
				'noAppointments'     => __( 'No appointments in this timeframe', 'client-sync' ),
				'today'              => __( 'Today', 'client-sync' ),
				'previous'           => __( 'Previous', 'client-sync' ),
				'next'               => __( 'Next', 'client-sync' ),
				'dayView'            => __( 'Day', 'client-sync' ),
				'weekView'           => __( 'Week', 'client-sync' ),
				'monthView'          => __( 'Month', 'client-sync' ),
				'selectResource'     => __( 'Resources', 'client-sync' ),
				// Status labels
				'alwaysAvailable'    => __( 'Always Available', 'client-sync' ),
				'alwaysAvailableDesc' => __( 'No schedule restrictions', 'client-sync' ),
				'openHours'          => __( 'Open Hours', 'client-sync' ),
				'openHoursDesc'      => __( 'Resource availability schedule', 'client-sync' ),
				'bookableSlot'       => __( 'Bookable Slot', 'client-sync' ),
				'bookableSlotDesc'   => __( 'Available for booking', 'client-sync' ),
				'booked'             => __( 'Booked', 'client-sync' ),
				'bookedDesc'         => __( 'Confirmed appointment', 'client-sync' ),
				'blocked'            => __( 'Blocked', 'client-sync' ),
				'blockedDesc'        => __( 'Unavailable', 'client-sync' ),
				// Legend sections
				'legendTitle'        => __( 'Timeline Key', 'client-sync' ),
				'statusSection'      => __( 'Status', 'client-sync' ),
				'dimensionTypesSection' => __( 'Types', 'client-sync' ),
				// Resource interaction
				'clickToEdit'        => __( 'Click to edit resource settings', 'client-sync' ),
				// Zoom controls
				'zoom'               => __( 'Zoom', 'client-sync' ),
				'zoomIn'             => __( 'Zoom In (smaller time slots)', 'client-sync' ),
				'zoomOut'            => __( 'Zoom Out (larger time slots)', 'client-sync' ),
				'zoomReset'          => __( 'Reset zoom to default (1 hour)', 'client-sync' ),
				'currentZoom'        => __( 'Current zoom level', 'client-sync' ),
				'zoomHint'           => __( 'Tip: Ctrl + scroll to zoom', 'client-sync' ),
				// Resize handle
				'resizeHandle'       => __( 'Drag to resize timeline height', 'client-sync' ),
				// View toggle
				'compact'            => __( 'Compact', 'client-sync' ),
				'expanded'           => __( 'Expanded', 'client-sync' ),
				'compactView'        => __( 'Compact rows (show details on hover)', 'client-sync' ),
				'expandView'         => __( 'Expand rows to show full text', 'client-sync' ),
			],
		];
	}

	private function get_available_resources( $resource_types ) {
		$resources = [];

		foreach ( $resource_types as $type_or_id ) {
			if ( is_numeric( $type_or_id ) ) {
				$post = get_post( (int) $type_or_id );
				
				if ( $post && $post->post_status === 'publish' ) {
					$resources[] = [
						'id'    => $post->ID,
						'name'  => $post->post_title,
						'type'  => $post->post_type,
						'color' => get_post_meta( $post->ID, Constants::META_COLOR, true ) ?: '#3b82f6',
						'slug'  => $post->post_name,
					];
				}
			} else {
				$posts = get_posts( [
					'post_type'      => $type_or_id,
					'posts_per_page' => 200,
					'no_found_rows'  => true,
					'post_status'    => 'publish',
					'orderby'        => 'title',
					'order'          => 'ASC',
				] );

				foreach ( $posts as $post ) {
					$resources[] = [
						'id'    => $post->ID,
						'name'  => $post->post_title,
						'type'  => $type_or_id,
						'color' => get_post_meta( $post->ID, Constants::META_COLOR, true ) ?: '#3b82f6',
						'slug'  => $post->post_name,
					];
				}
			}
		}

		return $resources;
	}

}

new Timeline_Shortcode();