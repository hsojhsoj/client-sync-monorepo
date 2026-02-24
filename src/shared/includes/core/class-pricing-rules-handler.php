<?php
/**
 * File: src/shared/includes/core/class-pricing-rules-handler.php
 * Handles WooCommerce product pricing rules meta box and saving.
 *
 * Extracted from PostType_Manager to reduce class size.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */
namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pricing_Rules_Handler {

	/**
	 * Register WordPress hooks for pricing rules.
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes_product', [ $this, 'add_pricing_rules_meta_box' ] );
		add_action( 'save_post_product', [ $this, 'save_pricing_rules_meta_data' ] );
	}

	public function add_pricing_rules_meta_box() {
		add_meta_box(
			'clisyc-pricing-rules-metabox',
			__( 'Client Sync: Dynamic Pricing Rules', 'client-sync' ),
			[ $this, 'render_pricing_rules_meta_box' ],
			'product',
			'normal',
			'default'
		);
	}

	public function render_pricing_rules_meta_box( \WP_Post $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_virtual() ) {
			echo '<p>' . esc_html__( 'Dynamic pricing rules are only available for "Simple" and "Virtual" products used for Client Sync bookings.', 'client-sync' ) . '</p>';
			return;
		}
		// --- START: THE LINKING BRIDGE (ROBUST VERSION) ---
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$linking_post_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_clisyc_wc_product_id' AND meta_value = %d LIMIT 1",
			$post->ID
		) );
		if ( $linking_post_id ) {
			$cs_post = get_post( $linking_post_id );
			if ( $cs_post ) {
				$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
				$is_dimension = ! empty( $registry['dimensions'][ $cs_post->post_type ]['enabled'] );
				if ( $is_dimension ) {
					$cpt_obj = get_post_type_object( $cs_post->post_type );
					$label   = $cpt_obj ? $cpt_obj->labels->singular_name : __( 'Item', 'client-sync' );

					echo '<div style="background: #f0f6fc; border: 1px solid #d1e9ff; padding: 10px; border-radius: 4px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">';

					printf(
						'<span>' . wp_kses(
							/* translators: 1: Dimension Label, 2: Post Title */
							__( 'Linked to %1$s: <strong>%2$s</strong>', 'client-sync' ),
							array( 'strong' => array() )
						) . '</span>',
						esc_html( $label ),
						esc_html( $cs_post->post_title )
					);
					printf(
						'<a href="%s" class="button button-secondary">%s</a>',
						esc_url( get_edit_post_link( $cs_post->ID ) ),
						/* translators: %s: The singular label of a dimension type (e.g., "Service", "Practitioner"). */
						sprintf( esc_html__( 'Edit %s', 'client-sync' ), esc_html( $label ) )
					);
					echo '</div>';
				}
			}
		}
		// --- END: THE LINKING BRIDGE ---
		wp_nonce_field( 'clisyc_save_pricing_rules_nonce_' . $post->ID, 'clisyc_pricing_rules_nonce' );
		$rules = get_post_meta( $post->ID, Constants::META_PRICING_RULES, true );
		if ( ! is_array( $rules ) ) {
			$rules = [];
		}
		?>
		<div id="clisyc-pricing-rules-container">
			<p class="description"><?php esc_html_e( 'These rules apply to Multi-Day bookings. Rules are applied in the order they appear. The base price is taken from the "Regular price" field above.', 'client-sync' ); ?></p>
			<div id="clisyc-pricing-rules-list">
				<?php foreach ( $rules as $index => $rule ) : ?>
					<?php $this->render_pricing_rule_row( $index, $rule ); ?>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button" id="clisyc-add-pricing-rule"><?php esc_html_e( 'Add Rule', 'client-sync' ); ?></button>
		</div>
		<!-- Template for new rows (script tags are not submitted by the browser) -->
		<script type="text/html" id="clisyc-pricing-rule-template">
			<?php $this->render_pricing_rule_row( '{{index}}' ); ?>
		</script>
		<script>
			jQuery(document).ready(function($) {
				let ruleIndex = <?php echo count( $rules ); ?>;
				$('#clisyc-add-pricing-rule').on('click', function() {
					const template = $('#clisyc-pricing-rule-template').html().replace(/{{index}}/g, ruleIndex);
					$('#clisyc-pricing-rules-list').append(template);
					ruleIndex++;
					toggleRuleFields();
				});
				$('#clisyc-pricing-rules-list').on('click', '.clisyc-remove-rule', function() {
					$(this).closest('.clisyc-pricing-rule').remove();
				});
				const toggleRuleFields = () => {
					$('.clisyc-pricing-rule').each(function() {
						const $rule = $(this);
						const type = $rule.find('.clisyc-rule-type').val();
						$rule.find('.clisyc-rule-field').hide();
						$rule.find('.clisyc-rule-field-' + type).show();
					});
				};
				$('#clisyc-pricing-rules-list').on('change', '.clisyc-rule-type', toggleRuleFields);
				toggleRuleFields();
			});
		</script>
		<?php
	}

	private function render_pricing_rule_row( $index, $rule = null ) {
		$type      = $rule['type'] ?? 'seasonal';
		$mode      = $rule['mode'] ?? 'set';
		$value     = $rule['value'] ?? '';
		$start     = $rule['start'] ?? '';
		$end       = $rule['end'] ?? '';
		$days      = $rule['days'] ?? [];
		$min_days  = $rule['min_days'] ?? '';
		$max_days  = $rule['max_days'] ?? '';
		?>
		<div class="clisyc-pricing-rule" style="border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; background: #f9f9f9;">
			<button type="button" class="button button-small clisyc-remove-rule" style="float: right;"><?php esc_html_e( 'Remove', 'client-sync' ); ?></button>
			<table style="width: 100%;">
				<tr>
					<td><strong><?php esc_html_e( 'Type', 'client-sync' ); ?></strong></td>
					<td>
						<select name="_clisyc_pricing_rules[<?php echo esc_attr( $index ); ?>][type]" class="clisyc-rule-type">
							<option value="seasonal" <?php selected( $type, 'seasonal' ); ?>><?php esc_html_e( 'Seasonal/Date Range', 'client-sync' ); ?></option>
							<option value="dow" <?php selected( $type, 'dow' ); ?>><?php esc_html_e( 'Day of Week', 'client-sync' ); ?></option>
							<option value="los" <?php selected( $type, 'los' ); ?>><?php esc_html_e( 'Length of Stay', 'client-sync' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="clisyc-rule-field clisyc-rule-field-seasonal">
					<td><strong><?php esc_html_e( 'Date Range', 'client-sync' ); ?></strong></td>
					<td>
						<input type="date" name="_clisyc_pricing_rules[<?php echo esc_attr( $index ); ?>][start]" value="<?php echo esc_attr( $start ); ?>">
						<span>to</span>
						<input type="date" name="_clisyc_pricing_rules[<?php echo esc_attr( $index ); ?>][end]" value="<?php echo esc_attr( $end ); ?>">
					</td>
				</tr>
				<tr class="clisyc-rule-field clisyc-rule-field-dow">
					<td><strong><?php esc_html_e( 'Days', 'client-sync' ); ?></strong></td>
					<td>
						<?php foreach ( [ 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun' ] as $day_index => $day_abbr ) : ?>
							<label><input type="checkbox" name="_clisyc_pricing_rules[<?php echo esc_attr( $index ); ?>][days][]" value="<?php echo esc_attr( $day_index ); ?>" <?php checked( in_array( (string) $day_index, $days, true ) ); ?>> <?php echo esc_html( $day_abbr ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr class="clisyc-rule-field clisyc-rule-field-los">
					<td><strong><?php esc_html_e( 'Duration (nights)', 'client-sync' ); ?></strong></td>
					<td>
						<input type="number" min="1" placeholder="<?php esc_attr_e( 'Min', 'client-sync' ); ?>" name="_clisyc_pricing_rules[<?php echo esc_attr( $index ); ?>][min_days]" value="<?php echo esc_attr( $min_days ); ?>" style="width: 80px;">
						<span>to</span>
						<input type="number" min="1" placeholder="<?php esc_attr_e( 'Max', 'client-sync' ); ?>" name="_clisyc_pricing_rules[<?php echo esc_attr( $index ); ?>][max_days]" value="<?php echo esc_attr( $max_days ); ?>" style="width: 80px;">
					</td>
				</tr>
				 <tr>
					<td><strong><?php esc_html_e( 'Adjustment', 'client-sync' ); ?></strong></td>
					<td>
						<select name="_clisyc_pricing_rules[<?php echo esc_attr( $index ); ?>][mode]">
							<option value="set" <?php selected( $mode, 'set' ); ?>><?php esc_html_e( 'Set new daily price to ($)', 'client-sync' ); ?></option>
							<option value="add" <?php selected( $mode, 'add' ); ?>><?php esc_html_e( 'Add to daily price (+ $)', 'client-sync' ); ?></option>
							<option value="subtract" <?php selected( $mode, 'subtract' ); ?>><?php esc_html_e( 'Subtract from daily price (- $)', 'client-sync' ); ?></option>
							<option value="add_percent" <?php selected( $mode, 'add_percent' ); ?>><?php esc_html_e( 'Increase daily price by (%)', 'client-sync' ); ?></option>
							<option value="subtract_percent" <?php selected( $mode, 'subtract_percent' ); ?>><?php esc_html_e( 'Decrease price by (%)', 'client-sync' ); ?></option>
						</select>
						<input type="number" step="0.01" name="_clisyc_pricing_rules[<?php echo esc_attr( $index ); ?>][value]" value="<?php echo esc_attr( $value ); ?>" style="width: 100px;">
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	public function save_pricing_rules_meta_data( int $post_id ) {
		if ( ! isset( $_POST['clisyc_pricing_rules_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clisyc_pricing_rules_nonce'] ), 'clisyc_save_pricing_rules_nonce_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array keys and values are sanitized in loop below.
		$raw_rules       = isset( $_POST['_clisyc_pricing_rules'] ) && is_array( $_POST['_clisyc_pricing_rules'] ) ? wp_unslash( $_POST['_clisyc_pricing_rules'] ) : [];
		$sanitized_rules = [];
		foreach ( $raw_rules as $rule ) {
			if ( empty( $rule['type'] ) ) {
				continue;
			}
			$sane_rule = [
				'type'  => sanitize_key( $rule['type'] ),
				'mode'  => sanitize_key( $rule['mode'] ?? 'set' ),
				'value' => sanitize_text_field( $rule['value'] ?? '' ),
			];
			switch ( $sane_rule['type'] ) {
				case 'seasonal':
					$sane_rule['start'] = sanitize_text_field( $rule['start'] ?? '' );
					$sane_rule['end']   = sanitize_text_field( $rule['end'] ?? '' );
					break;
				case 'dow':
					$sane_rule['days'] = isset( $rule['days'] ) && is_array( $rule['days'] ) ? array_map( 'absint', $rule['days'] ) : [];
					break;
				case 'los':
					$sane_rule['min_days'] = absint( $rule['min_days'] ?? 0 );
					$sane_rule['max_days'] = absint( $rule['max_days'] ?? 0 );
					break;
			}
			$sanitized_rules[] = $sane_rule;
		}
		update_post_meta( $post_id, Constants::META_PRICING_RULES, $sanitized_rules );
	}
}
