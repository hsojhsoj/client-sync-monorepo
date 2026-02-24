<?php
/**
 * Handles the [clisyc_debug_appointments] shortcode.
 *
 * Renders a diagnostic view of appointment data for troubleshooting.
 * Only works for logged-in users.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Debug_Appointments_Shortcode {

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_debug_appointments', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the debug appointments shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>Please log in to view debug info.</p>';
		}

		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'manage_options' );

		ob_start();
		?>
		<div style="background: #f5f5f5; padding: 20px; border: 1px solid #ddd; font-family: monospace; font-size: 13px; margin: 20px 0;">
			<h3 style="margin-top: 0;">🔍 Appointment Debug Info</h3>

			<p><strong>User ID:</strong> <?php echo esc_html( $user_id ); ?></p>
			<p><strong>Is Admin:</strong> <?php echo $is_admin ? 'Yes' : 'No'; ?></p>
			<p><strong>Current Time (UTC):</strong> <?php echo esc_html( current_time( 'mysql', 1 ) ); ?></p>
			<p><strong>Current Time (Local):</strong> <?php echo esc_html( current_time( 'mysql', 0 ) ); ?></p>
			<p><strong>WordPress Timezone:</strong> <?php echo esc_html( wp_timezone_string() ); ?></p>

			<hr>

			<?php
			// Query all appointments for this user
			$args = [
				'post_type'      => Constants::POST_TYPE_APPOINTMENT,
				'post_status'    => 'any',
				'author'         => $user_id,
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			];

			$query = new \WP_Query( $args );
			?>

			<h4>📋 Your Appointments (<?php echo esc_html( $query->found_posts ); ?> total)</h4>

			<?php if ( $query->have_posts() ) : ?>
				<table style="width: 100%; border-collapse: collapse; background: #fff;">
					<thead>
						<tr style="background: #e0e0e0;">
							<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">ID</th>
							<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">Title</th>
							<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">Status</th>
							<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">Post Date</th>
							<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">_clisyc_time_slot</th>
							<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">_clisyc_booking_mode</th>
							<th style="padding: 8px; border: 1px solid #ccc; text-align: left;">Parsed OK?</th>
						</tr>
					</thead>
					<tbody>
						<?php
						while ( $query->have_posts() ) :
							$query->the_post();
							?>
							<?php
							$post_id      = get_the_ID();
							$time_slot    = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );
							$booking_mode = get_post_meta( $post_id, Constants::META_BOOKING_MODE, true );

							// Try to parse the time slot
							$parsed_ok      = false;
							$parsed_display = '';
							if ( $time_slot ) {
								try {
									$dt             = new \DateTime( $time_slot );
									$parsed_ok      = true;
									$parsed_display = $dt->format( 'Y-m-d H:i:s' );
								} catch ( \Exception $e ) {
									$parsed_display = 'PARSE ERROR: ' . $e->getMessage();
								}
							}
							?>
							<tr>
								<td style="padding: 8px; border: 1px solid #ccc;"><?php echo esc_html( $post_id ); ?></td>
								<td style="padding: 8px; border: 1px solid #ccc;"><?php echo esc_html( get_the_title() ); ?></td>
								<td style="padding: 8px; border: 1px solid #ccc;">
									<span style="padding: 2px 6px; background: #e0e0e0; border-radius: 3px;">
										<?php echo esc_html( get_post_status() ); ?>
									</span>
								</td>
								<td style="padding: 8px; border: 1px solid #ccc;"><?php echo esc_html( get_the_date( 'Y-m-d H:i:s' ) ); ?></td>
								<td style="padding: 8px; border: 1px solid #ccc;">
									<code style="background: #fff3cd; padding: 2px 4px;">
										<?php echo esc_html( $time_slot ?: '(empty)' ); ?>
									</code>
								</td>
								<td style="padding: 8px; border: 1px solid #ccc;"><?php echo esc_html( $booking_mode ?: '(not set)' ); ?></td>
								<td style="padding: 8px; border: 1px solid #ccc;">
									<?php if ( $parsed_ok ) : ?>
										<span style="color: green;">✓ <?php echo esc_html( $parsed_display ); ?></span>
									<?php elseif ( $time_slot ) : ?>
										<span style="color: red;">✗ <?php echo esc_html( $parsed_display ); ?></span>
									<?php else : ?>
										<span style="color: gray;">N/A</span>
									<?php endif; ?>
								</td>
							</tr>

							<?php if ( $is_admin ) : ?>
							<tr>
								<td colspan="7" style="padding: 8px; border: 1px solid #ccc; background: #f9f9f9;">
									<details>
										<summary style="cursor: pointer;">Show all meta for post #<?php echo esc_html( $post_id ); ?></summary>
										<pre style="margin: 10px 0; padding: 10px; background: #fff; overflow-x: auto;"><?php
											$all_meta    = get_post_meta( $post_id );
											$clisyc_meta = [];
											foreach ( $all_meta as $key => $value ) {
												if ( strpos( $key, 'clisyc' ) !== false || strpos( $key, '_clisyc' ) !== false ) {
													$clisyc_meta[ $key ] = maybe_unserialize( $value[0] );
												}
											}
											// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Intentional debug output for admin troubleshooting.
											echo esc_html( print_r( $clisyc_meta, true ) );
										?></pre>
									</details>
								</td>
							</tr>
							<?php endif; ?>

						<?php endwhile; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p style="color: #666;">No appointments found for your account.</p>
			<?php endif; ?>

			<?php wp_reset_postdata(); ?>

			<hr>

			<h4>🔧 Quick Checks</h4>
			<ul>
				<li><strong>Success Page ID:</strong> <?php echo esc_html( get_option( Constants::OPTION_BOOKING_SUCCESS_PAGE, '(not set)' ) ); ?></li>
				<li><strong>Appointment View Page ID:</strong> <?php echo esc_html( get_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, '(not set)' ) ); ?></li>
				<li><strong>WC Integration Enabled:</strong> <?php echo get_option( Constants::OPTION_WC_ENABLED, false ) ? 'Yes' : 'No'; ?></li>
			</ul>

			<p style="margin-top: 20px; color: #999; font-size: 11px;">
				⚠️ Remove this debug shortcode in production.
			</p>
		</div>
		<?php
		return ob_get_clean();
	}
}
