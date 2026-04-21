<?php
/**
 * File: src/shared/includes/frontend/views/view-dimension-grid.php
 * Frontend view for the [clisyc_dimension_grid] shortcode.
 * Renders a responsive card grid of dimension items with booking links.
 * Each card displays the item's assigned accent color via --card-accent.
 *
 * Receives from shortcode: $items_data, $columns_count, $show_image,
 *                          $show_excerpt, $show_price, $show_duration,
 *                          $dim_icon, $dim_slug, $button_text, $link_to
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="clisyc-dimension-grid clisyc-dimension-grid--cols-<?php echo esc_attr( $columns_count ); ?>"
     data-dimension="<?php echo esc_attr( $dim_slug ); ?>">
	<div class="clisyc-dimension-grid__list">
		<?php foreach ( $items_data as $item ) : ?>
		<div class="clisyc-dimension-card"
		     data-item-id="<?php echo esc_attr( $item['id'] ); ?>"
		     style="--card-accent: <?php echo esc_attr( $item['color'] ); ?>">

			<?php if ( $show_image ) : ?>
				<div class="clisyc-dimension-card__image">
					<?php if ( $item['has_thumbnail'] ) : ?>
						<a href="<?php echo esc_url( $item['booking_url'] ); ?>"
						   aria-label="<?php echo esc_attr( $item['title'] ); ?>">
							<?php echo wp_get_attachment_image( $item['thumbnail_id'], 'medium_large', false, [ 'loading' => 'lazy' ] ); ?>
						</a>
					<?php else : ?>
						<div class="clisyc-dimension-card__placeholder">
							<span class="<?php echo esc_attr( $dim_icon ); ?>" aria-hidden="true"></span>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="clisyc-dimension-card__body">
				<h3 class="clisyc-dimension-card__title">
					<a href="<?php echo esc_url( $item['booking_url'] ); ?>">
						<?php echo esc_html( $item['title'] ); ?>
					</a>
				</h3>

				<?php if ( $show_duration && ! empty( $item['duration'] ) ) : ?>
					<div class="clisyc-dimension-card__meta clisyc-dimension-card__duration">
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<?php
						$minutes = absint( $item['duration'] );
						if ( $minutes >= 60 ) {
							$hours     = floor( $minutes / 60 );
							$remaining = $minutes % 60;
							if ( $remaining > 0 ) {
								/* translators: %1$d: hours, %2$d: remaining minutes. */
								printf( esc_html__( '%1$dh %2$dm', 'client-sync' ), $hours, $remaining );
							} else {
								/* translators: %d: number of hours. */
								printf( esc_html( _n( '%d hour', '%d hours', $hours, 'client-sync' ) ), $hours );
							}
						} else {
							/* translators: %d: number of minutes. */
							printf( esc_html__( '%d min', 'client-sync' ), $minutes );
						}
						?>
					</div>
				<?php endif; ?>

				<?php if ( $show_price && ! empty( $item['price']['formatted'] ) ) : ?>
					<div class="clisyc-dimension-card__meta clisyc-dimension-card__price">
						<span class="dashicons dashicons-tag" aria-hidden="true"></span>
						<?php echo esc_html( $item['price']['formatted'] ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $show_excerpt && ! empty( $item['excerpt'] ) ) : ?>
					<div class="clisyc-dimension-card__excerpt">
						<?php echo esc_html( $item['excerpt'] ); ?>
					</div>
				<?php endif; ?>

				<div class="clisyc-dimension-card__action">
					<a href="<?php echo esc_url( $item['booking_url'] ); ?>"
					   class="clisyc-dimension-card__btn">
						<?php echo esc_html( $button_text ); ?>
						<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
					</a>
				</div>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
</div>
