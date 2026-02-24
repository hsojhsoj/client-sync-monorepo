<?php
/**
 * File: src/shared/includes/frontend/views/view-membership-plans.php
 * Frontend view for the [clisyc_membership_plans] shortcode.
 * Renders a responsive pricing card grid with subscribe buttons.
 *
 * Receives from shortcode: $plans_data, $columns_class, $highlight_id,
 *                          $is_logged_in, $login_url, $ajax_url
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="clisyc-membership-plans <?php echo esc_attr( $columns_class ); ?>">
	<div class="clisyc-plans-grid">
		<?php foreach ( $plans_data as $plan ) :
			$card_classes = 'clisyc-plan-card';
			if ( $plan['is_current'] ) {
				$card_classes .= ' clisyc-plan-card--current';
			}
			if ( $plan['id'] === $highlight_id ) {
				$card_classes .= ' clisyc-plan-card--highlighted';
			}
		?>
		<div class="<?php echo esc_attr( $card_classes ); ?>" data-plan-id="<?php echo esc_attr( $plan['id'] ); ?>">

			<?php if ( $plan['trial_days'] > 0 ) : ?>
				<div class="clisyc-plan-badge clisyc-plan-badge--trial">
					<?php
					printf(
						/* translators: %d: number of free trial days. */
						esc_html__( '%d-Day Free Trial', 'client-sync' ),
						$plan['trial_days']
					);
					?>
				</div>
			<?php elseif ( $plan['is_current'] ) : ?>
				<div class="clisyc-plan-badge clisyc-plan-badge--current">
					<?php esc_html_e( 'Current Plan', 'client-sync' ); ?>
				</div>
			<?php elseif ( $plan['id'] === $highlight_id ) : ?>
				<div class="clisyc-plan-badge clisyc-plan-badge--recommended">
					<?php esc_html_e( 'Recommended', 'client-sync' ); ?>
				</div>
			<?php endif; ?>

			<h3 class="clisyc-plan-title"><?php echo esc_html( $plan['title'] ); ?></h3>

			<?php if ( $plan['price_display'] ) : ?>
				<div class="clisyc-plan-price"><?php echo esc_html( $plan['price_display'] ); ?></div>
			<?php endif; ?>

			<?php if ( $plan['description'] ) : ?>
				<p class="clisyc-plan-description"><?php echo esc_html( $plan['description'] ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $plan['benefits'] ) ) : ?>
				<ul class="clisyc-plan-benefits">
					<?php foreach ( $plan['benefits'] as $benefit ) : ?>
						<li>
							<span class="dashicons dashicons-yes-alt"></span>
							<?php echo esc_html( $benefit ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="clisyc-plan-action">
				<?php if ( $plan['is_current'] ) : ?>
					<span class="clisyc-plan-btn clisyc-plan-btn--current">
						<span class="dashicons dashicons-yes"></span>
						<?php esc_html_e( 'Your Plan', 'client-sync' ); ?>
					</span>
				<?php elseif ( ! $is_logged_in ) : ?>
					<a href="<?php echo esc_url( $login_url ); ?>" class="clisyc-plan-btn clisyc-plan-btn--login">
						<?php esc_html_e( 'Log In to Subscribe', 'client-sync' ); ?>
					</a>
				<?php elseif ( $plan['has_stripe'] ) : ?>
					<button type="button"
							class="clisyc-plan-btn clisyc-plan-btn--subscribe clisyc-subscribe-btn"
							data-plan-id="<?php echo esc_attr( $plan['id'] ); ?>"
							data-nonce="<?php echo esc_attr( $plan['nonce'] ); ?>"
							data-ajax-url="<?php echo esc_url( $ajax_url ); ?>">
						<?php esc_html_e( 'Subscribe', 'client-sync' ); ?>
					</button>
				<?php else : ?>
					<span class="clisyc-plan-btn clisyc-plan-btn--unavailable">
						<?php esc_html_e( 'Coming Soon', 'client-sync' ); ?>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
</div>

<script>
jQuery(function($) {
	'use strict';
	$('.clisyc-subscribe-btn').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		if ($btn.prop('disabled')) return;
		$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Processing...', 'client-sync' ) ); ?>');

		$.post($btn.data('ajax-url'), {
			action: 'clisyc_subscribe',
			plan_id: $btn.data('plan-id'),
			nonce: $btn.data('nonce')
		}, function(response) {
			if (response.success && response.data && response.data.url) {
				window.location.href = response.data.url;
			} else {
				var msg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'An error occurred. Please try again.', 'client-sync' ) ); ?>';
				alert(msg);
				$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Subscribe', 'client-sync' ) ); ?>');
			}
		}).fail(function() {
			alert('<?php echo esc_js( __( 'Connection error. Please try again.', 'client-sync' ) ); ?>');
			$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Subscribe', 'client-sync' ) ); ?>');
		});
	});
});
</script>
