<?php
/**
 * File: src/shared/includes/admin/class-dimension-manager.php -> client-sync/includes/admin/class-dimension-manager.php
 * Manages the "Dimensions" admin page and settings.
 *
 * This class is responsible for the UI where administrators can enable CPTs
 * as schedulable dimensions, define their relationships, and set a primary dimension.
 * MODIFIED: Added logic to save the "Personnel Dimension" setting.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dimension_Manager {

	/**
	 * Registers all hooks for the Dimension Manager.
	 */
	public function register_hooks() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Registers the main setting for the dimension registry.
	 */
	public function register_settings() {
		// *** REFACTORED ***: Updated settings group and option name with new prefix.
		register_setting(
			'clisyc_dimension_settings_group',
			Constants::OPTION_DIMENSION_REGISTRY,
			[ $this, 'sanitize_dimension_registry' ]
		);
	}

	/**
	 * Sanitizes the dimension registry settings before saving to the database.
	 * This function handles all settings from the unified "System Setup" tab.
	 *
	 * @param array $input The raw input from the settings form or an import.
	 * @return array The sanitized settings array.
	 */
	public function sanitize_dimension_registry( $input ): array {
		if ( ! is_array( $input ) ) {
			// *** REFACTORED ***: Updated option name.
			return get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		}

		// If the input doesn't come from our settings form (it lacks 'primary_dimension'),
		// it's from an import and is already in the desired format. We'll do a simple pass-through.
		if ( ! isset( $input['primary_dimension'] ) ) {
			$sanitized_input = [
				'dimensions'    => [],
				'relationships' => $input['relationships'] ?? [],
				'filter_order'  => array_map( 'sanitize_key', $input['filter_order'] ?? [] ),
			];
			if ( ! empty( $input['dimensions'] ) && is_array( $input['dimensions'] ) ) {
				foreach ( $input['dimensions'] as $slug => $settings ) {
					$sanitized_input['dimensions'][ sanitize_key( $slug ) ] = [
						'enabled'          => ! empty( $settings['enabled'] ),
						'primary'          => ! empty( $settings['primary'] ),
						'frontend_visible' => ! empty( $settings['frontend_visible'] ),
						'is_resource'      => ! empty( $settings['is_resource'] ),
						'enable_sync'      => ! empty( $settings['enable_sync'] ), // NEW
					];
				}
			}
			return $sanitized_input;
		}

		// The rest of the function is the original logic for handling the form submission.
		// *** REFACTORED ***: Updated option names.
		$output       = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$custom_types = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );

		$current_primary_slug = null;
		if ( ! empty( $output['dimensions'] ) && is_array( $output['dimensions'] ) ) {
			foreach ( $output['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$current_primary_slug = $slug;
					break;
				}
			}
		}

		$new_primary_slug = isset( $input['primary_dimension'] ) ? sanitize_key( $input['primary_dimension'] ) : $current_primary_slug;
		$flush_rewrites   = false;

		if ( isset( $input['dimensions'] ) ) {
			$output['dimensions'] = []; // Start fresh to remove any CPTs that no longer exist.
			$primary_key_found    = false;

			$all_cpts = get_post_types( [ 'show_ui' => true ], 'objects' );
			foreach ( $all_cpts as $cpt_slug => $cpt_object ) {
				if ( in_array( $cpt_slug, [ 'attachment', 'page', Constants::POST_TYPE_APPOINTMENT ], true ) ) {
					continue;
				}

				$settings   = $input['dimensions'][ $cpt_slug ] ?? [];
				$is_enabled = ! empty( $settings['enabled'] );

				$output['dimensions'][ $cpt_slug ]['enabled']          = $is_enabled;
				$output['dimensions'][ $cpt_slug ]['frontend_visible'] = ! empty( $settings['frontend_visible'] );
				$output['dimensions'][ $cpt_slug ]['is_resource']      = ! empty( $settings['is_resource'] );
				$output['dimensions'][ $cpt_slug ]['enable_sync']      = ! empty( $settings['enable_sync'] ); // NEW

				// Handle 'public' status for user-created dimensions
				if ( isset( $custom_types[ $cpt_slug ] ) ) {
					$is_public = ! empty( $settings['public'] );
					if ( ( ! empty( $custom_types[ $cpt_slug ]['public'] ) ) !== $is_public ) {
						$custom_types[ $cpt_slug ]['public'] = $is_public;
						$flush_rewrites                      = true; // Flag to flush rewrite rules
					}
				}

				if ( $is_enabled && $cpt_slug === $new_primary_slug ) {
					$output['dimensions'][ $cpt_slug ]['primary'] = true;
					$primary_key_found                            = true;
				} else {
					$output['dimensions'][ $cpt_slug ]['primary'] = false;
				}
			}

			if ( ! $primary_key_found ) {
				foreach ( $output['dimensions'] as $slug => &$settings ) {
					if ( ! empty( $settings['enabled'] ) ) {
						$settings['primary'] = true;
						break;
					}
				}
				unset( $settings );
			}
		}

		// Sanitize filter order
		if ( isset( $input['filter_order'] ) ) {
			$output['filter_order'] = [];
			if ( is_array( $input['filter_order'] ) ) {
				foreach ( $input['filter_order'] as $slug ) {
					$sanitized_slug = sanitize_key( $slug );
					if ( ! empty( $output['dimensions'][ $sanitized_slug ]['enabled'] ) ) {
						$output['filter_order'][] = $sanitized_slug;
					}
				}
			}
		}

		if ( ! isset( $output['relationships'] ) ) {
			$output['relationships'] = [];
		}

		// Save the updated custom types if 'public' status changed.
		// *** REFACTORED ***: Updated option name.
		update_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, $custom_types );
		if ( $flush_rewrites ) {
			flush_rewrite_rules();
		}

		return $output;
	}
}