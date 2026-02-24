<?php
/**
 * File: src/shared/includes/admin/class-custom-fields-handler.php
 * Handles all admin-post.php form submissions for custom fields management.
 *
 * Extracted from class-form-handler.php following the Single Responsibility Principle.
 * This class is responsible solely for saving, updating, and deleting custom field
 * definitions for clients, appointments, and dimensions.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Custom_Fields_Handler {

	/**
	 * Register WordPress hooks for custom field form submissions.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_clisyc_save_client_custom_field', [ $this, 'handle_save_client_custom_fields' ] );
		add_action( 'admin_post_clisyc_save_appointment_custom_field', [ $this, 'handle_save_appointment_custom_fields' ] );
		add_action( 'admin_post_clisyc_save_dimension_custom_field', [ $this, 'handle_save_dimension_custom_fields' ] );
	}

	/**
	 * Handle saving appointment custom fields (add, update, delete).
	 *
	 * @return void
	 */
	public function handle_save_appointment_custom_fields() {
		$nonce      = isset( $_POST['clisyc_appointment_custom_field_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clisyc_appointment_custom_field_nonce'] ) ) : '';
		$sub_action = isset( $_POST['clisyc_sub_action'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_sub_action'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_save_appointment_custom_field_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'client-sync' ) );
		}

		$custom_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		$field_order   = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, [] );
		if ( ! is_array( $custom_fields ) ) {
			$custom_fields = [];
		}
		if ( ! is_array( $field_order ) ) {
			$field_order = [];
		}

		$redirect_url   = admin_url( 'admin.php?page=clisyc-custom-fields&cf_tab=appointments-cf' );
		$notice_type    = null;
		$notice_message = null;

		if ( empty( $sub_action ) ) {
			wp_die( esc_html__( 'Invalid action specified.', 'client-sync' ) );
		}

		if ( 'add_field' === $sub_action || 'update_field' === $sub_action ) {
			$field_key = '';
			if ( 'add_field' === $sub_action ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$field_key = isset( $_POST['field_key'] ) ? sanitize_key( strtolower( str_replace( ' ', '_', wp_unslash( $_POST['field_key'] ) ) ) ) : '';
			} else {
				$field_key = isset( $_POST['edit_field_key'] ) ? sanitize_key( wp_unslash( $_POST['edit_field_key'] ) ) : '';
			}

			if ( empty( $field_key ) ) {
				$notice_type    = 'error';
				$notice_message = ( 'add_field' === $sub_action ) ? __( 'Field Key is required.', 'client-sync' ) : __( 'Field to update not specified.', 'client-sync' );
			} elseif ( 'add_field' === $sub_action && ! preg_match( '/^[a-z0-9_]+$/', $field_key ) ) {
				$notice_type    = 'error';
				$notice_message = __( 'Field Key format invalid. Lowercase letters, numbers, and underscores only.', 'client-sync' );
			} elseif ( 'add_field' === $sub_action && isset( $custom_fields[ $field_key ] ) ) {
				$notice_type    = 'error';
				$notice_message = __( 'Field Key already exists.', 'client-sync' );
			} elseif ( 'update_field' === $sub_action && ! isset( $custom_fields[ $field_key ] ) ) {
				$notice_type    = 'error';
				$notice_message = __( 'Field Key to update does not exist.', 'client-sync' );
			} else {
				$field_type         = isset( $_POST['field_type'] ) ? sanitize_key( wp_unslash( $_POST['field_type'] ) ) : 'text';

				// Fix: Sanitize the JSON string using sanitize_textarea_field to allow quotes but block malicious scripts.
				$field_options_json = ( in_array( $field_type, [ 'select', 'multi_checkbox', 'radio' ], true ) && isset( $_POST['field_options'] ) ) ?
					sanitize_textarea_field( wp_unslash( $_POST['field_options'] ) ) :
					'[]';

				$sanitized_options  = [];
				if ( is_string( $field_options_json ) && ! empty( $field_options_json ) ) {
					$decoded_options = json_decode( $field_options_json, true );
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_options ) ) {
						foreach ( $decoded_options as $option ) {
							if ( is_array( $option ) && ! empty( $option['value'] ) ) {
								$sanitized_options[] = [
									'value' => sanitize_text_field( trim( $option['value'] ) ),
									'color' => isset( $option['color'] ) ? sanitize_hex_color( trim( $option['color'] ) ) : '',
								];
							}
						}
					}
				}

				$field_label            = isset( $_POST['field_label'] ) ? sanitize_text_field( wp_unslash( $_POST['field_label'] ) ) : '';
				$field_required         = isset( $_POST['field_required'] ) ? '1' : '0';
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$field_custom_html      = isset( $_POST['field_custom_html'] ) ? wp_unslash( $_POST['field_custom_html'] ) : ''; // wp_kses_post is applied below
				$field_layout           = isset( $_POST['field_layout'] ) ? sanitize_key( wp_unslash( $_POST['field_layout'] ) ) : '';
				$field_image_id         = isset( $_POST['field_image_id'] ) ? absint( wp_unslash( $_POST['field_image_id'] ) ) : 0;
				$field_track_progress   = isset( $_POST['field_track_progress'] ) ? '1' : '0';
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$conditional_logic_json = isset( $_POST['conditional_logic_json'] ) ? wp_unslash( $_POST['conditional_logic_json'] ) : ''; // Sanitized in its own method

				$field_data = [
					'label'             => $field_label,
					'type'              => $field_type,
					'options'           => $sanitized_options,
					'required'          => ( '1' === $field_required && ! in_array( $field_type, [ 'custom_html', 'image_map' ] ) ),
					'html_content'      => ( 'custom_html' === $field_type ) ? wp_kses_post( $field_custom_html ) : '',
					'layout'            => ( in_array( $field_type, [ 'multi_checkbox', 'radio' ], true ) && 'horizontal' === $field_layout ) ? 'horizontal' : 'vertical',
					'image_id'          => ( 'image_map' === $field_type ) ? $field_image_id : 0,
					'track_progress'    => ( in_array( $field_type, [ 'text', 'select', 'number' ] ) && '1' === $field_track_progress ),
					'conditional_logic' => $this->_sanitize_conditional_logic( $conditional_logic_json ),
				];

				if ( 'custom_html' !== $field_data['type'] ) {
					unset( $field_data['html_content'] );
				}
				if ( ! in_array( $field_data['type'], [ 'select', 'multi_checkbox', 'radio' ] ) ) {
					unset( $field_data['options'] );
				}
				if ( ! in_array( $field_data['type'], [ 'multi_checkbox', 'radio' ] ) ) {
					unset( $field_data['layout'] );
				}
				if ( 'image_map' !== $field_data['type'] ) {
					unset( $field_data['image_id'] );
				}
				if ( ! in_array( $field_data['type'], [ 'text', 'select', 'number' ] ) ) {
					unset( $field_data['track_progress'] );
				}

				$custom_fields[ $field_key ] = $field_data;
				if ( update_option( Constants::OPTION_APPOINTMENT_FIELDS, $custom_fields ) ) {
					if ( 'add_field' === $sub_action && ! in_array( $field_key, $field_order, true ) ) {
						$field_order[] = $field_key;
						update_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, $field_order );
					}
					$notice_type    = 'success';
					$notice_message = ( 'add_field' === $sub_action ) ? __( 'Appointment custom field added.', 'client-sync' ) : __( 'Appointment custom field updated.', 'client-sync' );
				} else {
					$notice_type    = 'info';
					$notice_message = __( 'No changes detected in appointment custom field.', 'client-sync' );
				}
			}
		} elseif ( 'delete_field' === $sub_action ) {
			$delete_field_key = isset( $_POST['delete_field_key'] ) ? sanitize_key( wp_unslash( $_POST['delete_field_key'] ) ) : '';
			if ( ! empty( $delete_field_key ) && isset( $custom_fields[ $delete_field_key ] ) ) {
				unset( $custom_fields[ $delete_field_key ] );
				if ( update_option( Constants::OPTION_APPOINTMENT_FIELDS, $custom_fields ) ) {
					$field_order    = array_values( array_diff( $field_order, [ $delete_field_key ] ) );
					update_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, $field_order );
					$notice_type    = 'success';
					$notice_message = __( 'Appointment custom field deleted.', 'client-sync' );
				}
			}
		}

		if ( $notice_type && $notice_message ) {
			set_transient( 'clisyc_custom_field_feedback', [ 'type' => $notice_type, 'message' => $notice_message ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		}
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle saving client custom fields (add, update, delete, list table settings).
	 *
	 * @return void
	 */
	public function handle_save_client_custom_fields() {
		$nonce      = isset( $_POST['clisyc_custom_field_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clisyc_custom_field_nonce'] ) ) : '';
		$sub_action = isset( $_POST['clisyc_sub_action'] ) ? sanitize_key( wp_unslash( $_POST['clisyc_sub_action'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'clisyc_save_client_custom_field_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'client-sync' ) );
		}

		$custom_fields = get_option( Constants::OPTION_CUSTOM_FIELDS, [] );
		$field_order   = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, [] );
		if ( ! is_array( $custom_fields ) ) {
			$custom_fields = [];
		}
		if ( ! is_array( $field_order ) ) {
			$field_order = [];
		}

		$redirect_url   = admin_url( 'admin.php?page=clisyc-custom-fields&cf_tab=clients-cf' );
		$notice_type    = null;
		$notice_message = null;

		if ( empty( $sub_action ) ) {
			wp_die( esc_html__( 'Invalid action specified.', 'client-sync' ) );
		}

		if ( 'save_list_table_settings' === $sub_action ) {
			$column_field = isset( $_POST['client_list_custom_field_column'] ) ? sanitize_key( wp_unslash( $_POST['client_list_custom_field_column'] ) ) : '';
			update_option( Constants::OPTION_CLIENT_LIST_CF_COLUMN, $column_field );
			$notice_type    = 'success';
			$notice_message = __( 'User list column setting saved.', 'client-sync' );
		} elseif ( 'add_field' === $sub_action || 'update_field' === $sub_action ) {
			$field_key = '';
			if ( 'add_field' === $sub_action ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$field_key = isset( $_POST['field_key'] ) ? sanitize_key( strtolower( str_replace( ' ', '_', wp_unslash( $_POST['field_key'] ) ) ) ) : '';
			} else {
				$field_key = isset( $_POST['edit_field_key'] ) ? sanitize_key( wp_unslash( $_POST['edit_field_key'] ) ) : '';
			}

			if ( empty( $field_key ) ) {
				$notice_type    = 'error';
				$notice_message = ( 'add_field' === $sub_action ) ? __( 'Field Key is required.', 'client-sync' ) : __( 'Field to update not specified.', 'client-sync' );
			} elseif ( 'add_field' === $sub_action && ! preg_match( '/^[a-z0-9_]+$/', $field_key ) ) {
				$notice_type    = 'error';
				$notice_message = __( 'Field Key format invalid.', 'client-sync' );
			} elseif ( 'add_field' === $sub_action && isset( $custom_fields[ $field_key ] ) ) {
				$notice_type    = 'error';
				$notice_message = __( 'Field Key already exists.', 'client-sync' );
			} elseif ( 'update_field' === $sub_action && ! isset( $custom_fields[ $field_key ] ) ) {
				$notice_type    = 'error';
				$notice_message = __( 'Field Key to update does not exist.', 'client-sync' );
			} else {
				$field_type         = isset( $_POST['field_type'] ) ? sanitize_key( wp_unslash( $_POST['field_type'] ) ) : 'text';

				// Fix: Sanitize the JSON string using sanitize_textarea_field to allow quotes but block malicious scripts.
				$field_options_json = ( in_array( $field_type, [ 'select', 'multi_checkbox', 'radio' ], true ) && isset( $_POST['field_options'] ) ) ?
				sanitize_textarea_field( wp_unslash( $_POST['field_options'] ) ) :
				'[]';

				$field_options_array     = json_decode( $field_options_json, true );
				$sanitized_field_options = [];
				if ( json_last_error() === JSON_ERROR_NONE && is_array( $field_options_array ) ) {
					foreach ( $field_options_array as $option_item ) {
						if ( is_array( $option_item ) && isset( $option_item['value'] ) ) {
							$sanitized_item = [
								'value' => sanitize_text_field( trim( $option_item['value'] ) ),
								'color' => isset( $option_item['color'] ) ? sanitize_hex_color( trim( $option_item['color'] ) ) : '',
							];
							if ( ! empty( $sanitized_item['value'] ) ) {
								$sanitized_field_options[] = $sanitized_item;
							}
						}
					}
				}

				$field_label       = isset( $_POST['field_label'] ) ? sanitize_text_field( wp_unslash( $_POST['field_label'] ) ) : '';
				$field_required    = isset( $_POST['field_required'] ) ? '1' : '0';
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$field_custom_html = isset( $_POST['field_custom_html'] ) ? wp_unslash( $_POST['field_custom_html'] ) : '';
				$field_layout      = isset( $_POST['field_layout'] ) ? sanitize_key( wp_unslash( $_POST['field_layout'] ) ) : '';
				$field_image_id    = isset( $_POST['field_image_id'] ) ? absint( wp_unslash( $_POST['field_image_id'] ) ) : 0;

				$field_data = [
					'label'        => $field_label,
					'type'         => $field_type,
					'required'     => ( '1' === $field_required && ! in_array( $field_type, [ 'custom_html', 'image_map' ], true ) ),
					'options'      => $sanitized_field_options,
					'html_content' => ( 'custom_html' === $field_type ) ? wp_kses_post( $field_custom_html ) : '',
					'layout'       => ( in_array( $field_type, [ 'multi_checkbox', 'radio' ], true ) && 'horizontal' === $field_layout ) ? 'horizontal' : 'vertical',
					'image_id'     => ( 'image_map' === $field_type ) ? $field_image_id : 0,
				];

				if ( 'custom_html' !== $field_data['type'] ) {
					unset( $field_data['html_content'] );
				}
				if ( ! in_array( $field_data['type'], [ 'select', 'multi_checkbox', 'radio' ], true ) ) {
					unset( $field_data['options'] );
				}
				if ( ! in_array( $field_data['type'], [ 'multi_checkbox', 'radio' ], true ) ) {
					unset( $field_data['layout'] );
				}
				if ( 'image_map' !== $field_data['type'] ) {
					unset( $field_data['image_id'] );
				}

				$custom_fields[ $field_key ] = $field_data;
				if ( update_option( Constants::OPTION_CUSTOM_FIELDS, $custom_fields ) ) {
					if ( 'add_field' === $sub_action && ! in_array( $field_key, $field_order, true ) ) {
						$field_order[] = $field_key;
						update_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, $field_order );
					}
					$notice_type    = 'success';
					$notice_message = ( 'add_field' === $sub_action ) ? __( 'Client custom field added.', 'client-sync' ) : __( 'Client custom field updated.', 'client-sync' );
				} else {
					$notice_type    = 'info';
					$notice_message = __( 'Client custom field not changed.', 'client-sync' );
				}
			}
		} elseif ( 'delete_field' === $sub_action ) {
			$delete_field_key = isset( $_POST['delete_field_key'] ) ? sanitize_key( wp_unslash( $_POST['delete_field_key'] ) ) : '';
			if ( ! empty( $delete_field_key ) && isset( $custom_fields[ $delete_field_key ] ) ) {
				unset( $custom_fields[ $delete_field_key ] );
				if ( update_option( Constants::OPTION_CUSTOM_FIELDS, $custom_fields ) ) {
					$field_order    = array_values( array_diff( $field_order, [ $delete_field_key ] ) );
					update_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, $field_order );
					$notice_type    = 'success';
					$notice_message = __( 'Client custom field deleted.', 'client-sync' );
				} else {
					$notice_type    = 'error';
					$notice_message = __( 'Failed to save changes after deleting client field definition.', 'client-sync' );
				}
			} else {
				$notice_type    = 'error';
				$notice_message = __( 'Field to delete was not found.', 'client-sync' );
			}
		}

		if ( $notice_type && $notice_message ) {
			set_transient( 'clisyc_custom_field_feedback', [ 'type' => $notice_type, 'message' => $notice_message ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
		}
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle saving dimension custom fields (add, update, delete).
	 *
	 * @return void
	 */
	public function handle_save_dimension_custom_fields() {
		check_admin_referer( 'clisyc_save_dimension_custom_field_action', 'clisyc_dimension_custom_field_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync' ) );
		}

		$redirect_url   = admin_url( 'admin.php?page=clisyc-custom-fields&cf_tab=dimensions-cf' );
		$unslashed_post = wp_unslash( $_POST );
		$sub_action     = sanitize_key( $unslashed_post['clisyc_sub_action'] ?? 'add_field' );

		$fields = get_option( Constants::OPTION_DIMENSION_FIELDS, [] );
		if ( ! is_array( $fields ) ) {
			$fields = [];
		}

		if ( 'delete_field' === $sub_action ) {
			$key_to_delete = sanitize_key( $unslashed_post['delete_field_key'] ?? '' );
			if ( ! empty( $key_to_delete ) && isset( $fields[ $key_to_delete ] ) ) {
				unset( $fields[ $key_to_delete ] );
				update_option( Constants::OPTION_DIMENSION_FIELDS, $fields );
				set_transient( 'clisyc_custom_field_feedback', [ 'type' => 'success', 'message' => 'Dimension Attribute deleted.' ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			}
		} elseif ( 'add_field' === $sub_action || 'update_field' === $sub_action ) {
			$field_key = ( 'add_field' === $sub_action )
				? sanitize_key( $unslashed_post['field_key'] ?? '' )
				: sanitize_key( $unslashed_post['edit_field_key'] ?? '' );

			if ( empty( $field_key ) ) {
				set_transient( 'clisyc_custom_field_feedback', [ 'type' => 'error', 'message' => 'Field Key is required.' ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			} elseif ( 'add_field' === $sub_action && isset( $fields[ $field_key ] ) ) {
				set_transient( 'clisyc_custom_field_feedback', [ 'type' => 'error', 'message' => 'Field Key already exists.' ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			} else {
				$applies_to = isset( $unslashed_post['field_applies_to'] ) && is_array( $unslashed_post['field_applies_to'] )
					? array_map( 'sanitize_key', $unslashed_post['field_applies_to'] )
					: [];

				$allowed_operators = [ '=', '>=', '<=' ];
				$comparison_op     = $unslashed_post['field_comparison_operator'] ?? '=';

				$fields[ $field_key ] = [
					'label'               => sanitize_text_field( $unslashed_post['field_label'] ?? '' ),
					'type'                => sanitize_key( $unslashed_post['field_type'] ?? 'text' ),
					'icon'                => sanitize_text_field( $unslashed_post['field_icon'] ?? 'dashicons-admin-generic' ),
					'filterable'          => ! empty( $unslashed_post['field_filterable'] ),
					'options'             => sanitize_textarea_field( $unslashed_post['field_options'] ?? '' ),
					'applies_to'          => $applies_to,
					'comparison_operator' => in_array( $comparison_op, $allowed_operators, true ) ? $comparison_op : '=',
				];

				update_option( Constants::OPTION_DIMENSION_FIELDS, $fields );
				$message = ( 'add_field' === $sub_action ) ? 'Dimension Attribute added.' : 'Dimension Attribute updated.';
				set_transient( 'clisyc_custom_field_feedback', [ 'type' => 'success', 'message' => $message ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			}
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Sanitize and validate conditional logic JSON data for appointment custom fields.
	 *
	 * @param string $json_data Raw JSON string containing conditional logic rules.
	 * @return array|null Sanitized conditional logic array, or null if invalid/empty.
	 */
	private function _sanitize_conditional_logic( string $json_data ): ?array {
		$data = json_decode( $json_data, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) || empty( $data['enabled'] ) || empty( $data['rules'] ) || ! is_array( $data['rules'] ) ) {
			return null;
		}
		$sanitized_logic           = [
			'enabled'   => true,
			'condition' => ( isset( $data['condition'] ) && 'any' === $data['condition'] ) ? 'any' : 'all',
			'rules'     => [],
		];
		$allowed_controller_fields = [ 'service_id' ];
		foreach ( $data['rules'] as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) || empty( $rule['operator'] ) || ! isset( $rule['value'] ) ) {
				continue;
			}
			$field_key = sanitize_key( $rule['field'] );
			if ( ! in_array( $field_key, $allowed_controller_fields, true ) ) {
				continue;
			}
			$sanitized_logic['rules'][] = [
				'field'    => $field_key,
				'operator' => ( 'is_not' === $rule['operator'] ) ? 'is_not' : 'is',
				'value'    => sanitize_text_field( $rule['value'] ),
			];
		}
		if ( empty( $sanitized_logic['rules'] ) ) {
			return null;
		}
		return $sanitized_logic;
	}
}
