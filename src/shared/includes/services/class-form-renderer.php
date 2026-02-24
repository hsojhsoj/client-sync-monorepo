<?php
/**
 * File: src/shared/includes/services/class-form-renderer.php
 * Handles the HTML rendering for frontend form fields.
 *
 * UPDATED: Added ARIA accessibility attributes (aria-required, aria-describedby,
 *          role="group", aria-label) for WCAG 2.1 AA compliance.
 * UPDATED: Added HTML5 validation attributes (min, max, maxlength, pattern,
 *          placeholder) for client-side validation.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Services
 */

namespace DependentMedia\ClientSync\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FormRenderer {

	/**
	 * Renders the HTML for a single custom field on a frontend form.
	 *
	 * @param string $field_name_attr  The 'name' attribute for the input.
	 * @param array  $field_definition The definition array for the field.
	 * @param mixed  $posted_value     The current value for the field.
	 * @param string $context          The context in which the field is being rendered.
	 * @param string $field_key        The unique key of the field.
	 * @param string $extra_classes    Additional CSS classes for the wrapper.
	 */
	public function render_frontend_custom_field( $field_name_attr, $field_definition, $posted_value = null, $context = 'default', $field_key = '', $extra_classes = '' ) {

		$field_type  = $field_definition['type'] ?? 'text';
		$label       = $field_definition['label'] ?? $field_key;
		$required    = ! empty( $field_definition['required'] );
		$options     = $field_definition['options'] ?? [];
		$layout      = $field_definition['layout'] ?? 'vertical';
		$placeholder = $field_definition['placeholder'] ?? '';
		$description = $field_definition['description'] ?? '';

		// HTML5 validation attributes from field definition.
		$min       = $field_definition['min'] ?? null;
		$max       = $field_definition['max'] ?? null;
		$maxlength = $field_definition['maxlength'] ?? null;
		$pattern   = $field_definition['pattern'] ?? null;

		// ARIA identifiers.
		$field_id = 'clisyc_field_' . $field_key;
		$desc_id  = $field_id . '_desc';

		$render_wrapper_and_label = ( 'admin' !== $context );

		if ( 'custom_html' === $field_type ) {
			if ( ! empty( $field_definition['html_content'] ) ) {
				echo '<div class="clisyc-form-field clisyc-field-type-custom-html">' . wp_kses_post( $field_definition['html_content'] ) . '</div>';
			}
			return;
		}

		if ( $render_wrapper_and_label ) {
			$wrapper_classes = 'clisyc-form-field clisyc-field-type-' . esc_attr( $field_type ) . ' clisyc-form-field-' . esc_attr( str_replace( '_', '-', $field_key ) ) . ' ' . esc_attr( $extra_classes );
			echo '<div class="' . esc_attr( trim( $wrapper_classes ) ) . '">';
			echo '<label for="' . esc_attr( $field_id ) . '">' . esc_html( $label );
			if ( $required ) {
				echo ' <span class="required" aria-hidden="true">*</span>';
			}
			echo '</label>';
		}

		// Build shared attribute string for text-like inputs.
		$shared_attrs = $this->build_input_attrs( $required, $placeholder, $description, $desc_id, $min, $max, $maxlength, $pattern );

		switch ( $field_type ) {
			case 'number':
				printf(
					'<input type="number" name="%s" id="%s" value="%s" step="any"%s>',
					esc_attr( $field_name_attr ),
					esc_attr( $field_id ),
					esc_attr( $posted_value ?? '' ),
					$shared_attrs
				);
				break;

			case 'text':
				printf(
					'<input type="text" name="%s" id="%s" value="%s"%s>',
					esc_attr( $field_name_attr ),
					esc_attr( $field_id ),
					esc_attr( $posted_value ?? '' ),
					$shared_attrs
				);
				break;

			case 'phone':
				printf(
					'<input type="tel" name="%s" id="%s" value="%s"%s>',
					esc_attr( $field_name_attr ),
					esc_attr( $field_id ),
					esc_attr( $posted_value ?? '' ),
					$shared_attrs
				);
				break;

			case 'textarea':
				printf(
					'<textarea name="%s" id="%s" rows="5"%s>%s</textarea>',
					esc_attr( $field_name_attr ),
					esc_attr( $field_id ),
					$shared_attrs,
					esc_textarea( $posted_value ?? '' )
				);
				break;

			case 'select':
				echo '<select name="' . esc_attr( $field_name_attr ) . '" id="' . esc_attr( $field_id ) . '"';
				if ( $required ) {
					echo ' required aria-required="true"';
				}
				if ( $description ) {
					echo ' aria-describedby="' . esc_attr( $desc_id ) . '"';
				}
				echo '>';
				echo '<option value="">' . esc_html__( '-- Select --', 'client-sync' ) . '</option>';
				if ( is_array( $options ) ) {
					foreach ( $options as $option_item ) {
						$option_value = is_array( $option_item ) ? ( $option_item['value'] ?? '' ) : $option_item;
						if ( $option_value ) {
							echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( $posted_value, $option_value, false ) . '>' . esc_html( $option_value ) . '</option>';
						}
					}
				}
				echo '</select>';
				break;

			case 'radio':
				echo '<div class="clisyc-radio-group ' . ( 'horizontal' === $layout ? 'clisyc-radio-group-horizontal' : '' ) . '" role="radiogroup" aria-label="' . esc_attr( $label ) . '">';
				if ( is_array( $options ) ) {
					foreach ( $options as $option_item ) {
						$option_value = is_array( $option_item ) ? ( $option_item['value'] ?? '' ) : $option_item;
						if ( $option_value ) {
							echo '<label><input type="radio" name="' . esc_attr( $field_name_attr ) . '" value="' . esc_attr( $option_value ) . '" ' . checked( $posted_value, $option_value, false ) . ( $required ? ' required' : '' ) . '> ' . esc_html( $option_value ) . '</label>';
						}
					}
				}
				echo '</div>';
				break;

			case 'multi_checkbox':
				echo '<div class="clisyc-checkbox-group ' . ( 'horizontal' === $layout ? 'clisyc-checkbox-group-horizontal' : '' ) . '" role="group" aria-label="' . esc_attr( $label ) . '">';
				$posted_array = is_array( $posted_value ) ? $posted_value : [];
				if ( is_array( $options ) ) {
					foreach ( $options as $option_item ) {
						$option_value = is_array( $option_item ) ? ( $option_item['value'] ?? '' ) : $option_item;
						if ( $option_value ) {
							echo '<label><input type="checkbox" name="' . esc_attr( $field_name_attr ) . '[]" value="' . esc_attr( $option_value ) . '" ' . checked( in_array( $option_value, $posted_array, true ), true, false ) . '> ' . esc_html( $option_value ) . '</label>';
						}
					}
				}
				echo '</div>';
				break;

			case 'image_map':
				// 1. Get the data
				$image_id  = ! empty( $field_definition['image_id'] ) ? absint( $field_definition['image_id'] ) : 0;
				$image_url = ! empty( $field_definition['image_url'] ) ? esc_url( $field_definition['image_url'] ) : '';

				// 2. Resolve the URL
				if ( $image_id > 0 ) {
					$resolved_url = wp_get_attachment_image_url( $image_id, 'large' );
					if ( ! $resolved_url ) {
						$resolved_url = wp_get_attachment_image_url( $image_id, 'full' );
					}
					if ( $resolved_url ) {
						$image_url = $resolved_url;
					}
				}

				// 3. Output the HTML if we have a URL from ANY source
				if ( ! empty( $image_url ) ) {
					echo '<div class="clisyc-image-marker-wrapper" role="application" aria-label="' . esc_attr( $label ) . '">';
					echo '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $label ) . '" data-id="' . esc_attr( $image_id ) . '">';
					echo '<div class="clisyc-marker-container"></div>';
					echo '</div>';

					$marker_data = ( ! empty( $posted_value ) ) ? $posted_value : '[]';
					echo '<input type="hidden" class="clisyc-image-marker-data" name="' . esc_attr( $field_name_attr ) . '" value="' . esc_attr( $marker_data ) . '">';

					echo '<div class="clisyc-image-marker-controls">';
					echo '<button type="button" class="button button-secondary clisyc-image-marker-undo">' . esc_html__( 'Undo Last Marker', 'client-sync' ) . '</button> ';
					echo '<button type="button" class="button button-secondary clisyc-image-marker-reset">' . esc_html__( 'Reset Markers', 'client-sync' ) . '</button>';
					echo '</div>';
				} else {
					echo '<p style="color: #d63638; padding: 10px; border: 1px solid #d63638;" role="alert">';
					esc_html_e( 'Error: Image could not be loaded. Please re-select the image in the Form Builder and save.', 'client-sync' );
					echo '</p>';
				}
				break;

			case 'readonly_filter_display':
				echo '<div class="clisyc-readonly-filter-display-container" aria-live="polite">';
				echo '<p><em>' . esc_html__( 'Your selection details will appear here.', 'client-sync' ) . '</em></p>';
				echo '</div>';
				break;
		}

		// Render optional description text below the field.
		if ( $render_wrapper_and_label && $description ) {
			echo '<p class="clisyc-field-description" id="' . esc_attr( $desc_id ) . '">' . esc_html( $description ) . '</p>';
		}

		if ( $render_wrapper_and_label ) {
			echo '</div>';
		}
	}

	/**
	 * Builds a string of shared HTML attributes for text-like inputs.
	 *
	 * Includes: required, aria-required, placeholder, aria-describedby,
	 *           min, max, maxlength, pattern.
	 *
	 * @param bool        $required    Whether the field is required.
	 * @param string      $placeholder Placeholder text.
	 * @param string      $description Field description for aria-describedby.
	 * @param string      $desc_id     ID of the description element.
	 * @param int|null    $min         Minimum value (number fields).
	 * @param int|null    $max         Maximum value (number fields).
	 * @param int|null    $maxlength   Maximum character length.
	 * @param string|null $pattern     Regex pattern for validation.
	 * @return string HTML attribute string (leading space included).
	 */
	private function build_input_attrs( bool $required, string $placeholder, string $description, string $desc_id, $min, $max, $maxlength, $pattern ): string {
		$attrs = '';

		if ( $required ) {
			$attrs .= ' required aria-required="true"';
		}
		if ( $placeholder ) {
			$attrs .= ' placeholder="' . esc_attr( $placeholder ) . '"';
		}
		if ( $description ) {
			$attrs .= ' aria-describedby="' . esc_attr( $desc_id ) . '"';
		}
		if ( null !== $min ) {
			$attrs .= ' min="' . esc_attr( $min ) . '"';
		}
		if ( null !== $max ) {
			$attrs .= ' max="' . esc_attr( $max ) . '"';
		}
		if ( null !== $maxlength ) {
			$attrs .= ' maxlength="' . esc_attr( $maxlength ) . '"';
		}
		if ( null !== $pattern ) {
			$attrs .= ' pattern="' . esc_attr( $pattern ) . '"';
		}

		return $attrs;
	}
}
