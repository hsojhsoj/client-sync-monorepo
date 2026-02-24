<?php
/**
 * File: src/shared/includes/admin/class-onboarding-wizard.php -> client-sync/includes/admin/class-onboarding-wizard.php
 * Handles the multi-step onboarding wizard for first-time setup.
 * REFACTORED: Now a "Business Model Selector" that installs pre-configured templates.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Onboarding_Wizard {

	private $step = '';
	private $steps = [];

	public function __construct() {
		$this->steps = [
			'welcome'    => [ 'title' => __( 'Welcome', 'client-sync' ) ],
			'dimensions' => [ 'title' => __( 'Choose Setup', 'client-sync' ) ],
			'pages'      => [ 'title' => __( 'Page Setup', 'client-sync' ) ],
			'finish'     => [ 'title' => __( 'Finish', 'client-sync' ) ],
		];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading navigational parameter 'step' for display logic, not processing data.
		$current_step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : key( $this->steps );
		$this->step   = array_key_exists( $current_step, $this->steps ) ? $current_step : key( $this->steps );
	}

	/**
	 * Static handler for POST requests, hooked to admin_init to run before headers are sent.
	 */
	public static function handle_post_on_init() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking for page and method, not processing data yet.
		if ( ! isset( $_GET['page'] ) || 'clisyc-setup' !== $_GET['page'] ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method ) {
			return;
		}

		$instance = new self();
		$instance->handle_wizard_steps();
	}

	public function render_wizard_page() {
		// *** REFACTORED ***: Updated page slug for URLs
		$wizard_page_url = admin_url( 'admin.php?page=clisyc-setup' );
		?>
		<div class="wrap cs-setup-wizard-wrap">
			<h1><?php esc_html_e( 'Client Sync Setup', 'client-sync' ); ?></h1>
			<nav class="cs-setup-steps">
				<?php
				$all_step_keys      = array_keys( $this->steps );
				$current_step_index = array_search( $this->step, $all_step_keys, true );
				if ( false === $current_step_index ) {
					$current_step_index = 0;
				}

				foreach ( $this->steps as $step_key => $step_data ) :
					$loop_step_index = array_search( $step_key, $all_step_keys, true );
					$is_accessible   = ( $loop_step_index <= $current_step_index );
					$classes         = [ 'cs-step' ];
					if ( $step_key === $this->step ) {
						$classes[] = 'is-active';
					}
					if ( $is_accessible ) {
						$classes[] = 'is-accessible';
					}
					?>
					<?php if ( $is_accessible ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'step', $step_key, $wizard_page_url ) ); ?>" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
							<?php echo esc_html( $step_data['title'] ); ?>
						</a>
					<?php else : ?>
						<span class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
							<?php echo esc_html( $step_data['title'] ); ?>
						</span>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>

			<div class="cs-setup-content">
				<?php
				$method_name = 'render_step_' . $this->step;
				if ( method_exists( $this, $method_name ) ) {
					$this->$method_name();
				}
				?>
			</div>
		</div>
		<?php
	}

	public function handle_wizard_steps() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		// *** REFACTORED ***: Added security checks for all POST submissions.
		if ( 'POST' !== $request_method || ! isset( $_POST['clisyc_wizard_nonce'] ) ) {
			return; // Only process actual form submissions for the wizard.
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['clisyc_wizard_nonce'] ), 'clisyc_wizard_action' ) ) {
			wp_die( 'Security check failed.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}

		$unslashed_post = wp_unslash( $_POST );

		switch ( $this->step ) {
			case 'dimensions':
				$setup_choice = isset( $unslashed_post['setup_choice'] ) ? sanitize_key( $unslashed_post['setup_choice'] ) : 'custom';
				if ( 'custom' !== $setup_choice ) {
					// *** REFACTORED ***: Use new constant
					$template_path = clisyc_PLUGIN_DIR . 'setup-templates/' . $setup_choice . '.json';
					if ( file_exists( $template_path ) ) {
						$this->wipe_all_dimension_data();
						$this->process_template_install( $template_path );
					}
				}
				wp_safe_redirect( esc_url_raw( $this->get_next_step_url() ) );
				exit;

			case 'pages':
				$this->process_create_pages();
				wp_safe_redirect( esc_url_raw( $this->get_next_step_url() ) );
				exit;
		}
	}

	private function render_step_welcome() {
		?>
		<h2><?php esc_html_e( 'Welcome to Client Sync!', 'client-sync' ); ?></h2>
		<p><?php esc_html_e( 'Thanks for installing! This quick wizard will help you set up the essential pages and settings to get started.', 'client-sync' ); ?></p>
		<p>
			<a href="<?php echo esc_url( $this->get_next_step_url() ); ?>" class="button button-primary button-hero">
				<?php esc_html_e( 'Let\'s Go!', 'client-sync' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc' ) ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Skip Setup', 'client-sync' ); ?>
			</a>
		</p>
		<?php
	}

	private function render_step_dimensions() {
		// *** REFACTORED ***: Use new constant
		$templates_dir  = clisyc_PLUGIN_DIR . 'setup-templates/';
		$template_files = file_exists( $templates_dir ) ? glob( $templates_dir . '*.json' ) : [];
		?>
		<h2><?php esc_html_e( 'Choose Your Setup', 'client-sync' ); ?></h2>
		<p><?php esc_html_e( 'Get started quickly by choosing a business model below, importing a configuration, or starting from scratch.', 'client-sync' ); ?></p>
		
		<!-- START: New Top Section for Primary Actions -->
		<div class="cs-setup-primary-actions">
			<div class="cs-setup-choice-box">
				<h3><?php esc_html_e( 'Start from Scratch', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'For advanced users. You will configure everything manually after this wizard.', 'client-sync' ); ?></p>
				<a href="<?php echo esc_url( $this->get_next_step_url() ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Start with Empty Setup', 'client-sync' ); ?>
				</a>
			</div>
			<div class="cs-setup-choice-box">
				<h3><?php esc_html_e( 'Import from File', 'client-sync' ); ?></h3>
				<p><?php esc_html_e( 'Upload a previously exported .json configuration file to set up your system.', 'client-sync' ); ?></p>
				
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"  onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure? Importing a file will overwrite your current configuration.', 'client-sync' ) ); ?>');">
					<input type="hidden" name="action" value="clisyc_import_settings">
					<?php wp_nonce_field( 'clisyc_import_settings_action', 'clisyc_import_settings_nonce' ); ?>
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( $this->get_next_step_url() ); ?>">
					<p><input type="file" name="clisyc_import_file" required accept=".json,application/json"></p>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Upload and Install', 'client-sync' ); ?>
					</button>
				</form>
			</div>
		</div>
		<!-- END: New Top Section -->

		<h3 class="cs-templates-heading"><?php esc_html_e( 'Or, Install a Pre-Configured Template', 'client-sync' ); ?></h3>
		<div class="cs-setup-choices-container">
			<?php if ( ! empty( $template_files ) ) : ?>
				<?php foreach ( $template_files as $file_path ) : ?>
					<?php
					$template_json = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$template_data = json_decode( $template_json, true );
					if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $template_data ) ) {
						continue;
					}
					$template_info = $template_data[0] ?? [];
					$file_slug     = basename( $file_path, '.json' );
					$title         = $template_info['template_meta']['title'] ?? ucwords( str_replace( ['-', '_'], ' ', $file_slug ) );
					$description   = $template_info['template_meta']['description'] ?? '';
					$is_recommended = ! empty( $template_info['template_meta']['recommended'] );
					$is_new         = ! empty( $template_info['template_meta']['new'] );
					$box_classes    = 'cs-setup-choice-box';
					if ( $is_recommended ) {
						$box_classes .= ' recommended';
					}
					if ( $is_new ) {
						$box_classes .= ' cs-template-new';
					}
					?>
					<div class="<?php echo esc_attr( $box_classes ); ?>">
						<h3>
							<?php echo esc_html( $title ); ?>
							<?php if ( $is_new ) : ?>
								<span class="cs-template-badge cs-template-badge--new"><?php esc_html_e( 'New', 'client-sync' ); ?></span>
							<?php endif; ?>
						</h3>
						<?php if ( $description ) : ?>
						<p><?php echo esc_html( $description ); ?></p>
						<?php endif; ?>
						<form method="post">
							<input type="hidden" name="clisyc_wizard_nonce" value="<?php echo esc_attr( wp_create_nonce( 'clisyc_wizard_action' ) ); ?>">
							<input type="hidden" name="setup_choice" value="<?php echo esc_attr( $file_slug ); ?>">
							<button type="submit" class="button button-primary button-hero">
								<?php esc_html_e( 'Select', 'client-sync' ); ?> →
							</button>
						</form>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<div class="cs-template-key">
			<span class="cs-template-key__item cs-template-key__item--recommended">
				<span class="cs-template-key__swatch"></span>
				<?php esc_html_e( 'Recommended', 'client-sync' ); ?>
			</span>
			<span class="cs-template-key__item cs-template-key__item--new">
				<span class="cs-template-key__swatch"></span>
				<?php esc_html_e( 'New', 'client-sync' ); ?>
			</span>
		</div>
		<?php
	}

	private function render_step_pages() {
		$pages_to_create = self::get_page_definitions();
		?>
		<h2><?php esc_html_e( 'Create Essential Pages', 'client-sync' ); ?></h2>
		<p><?php esc_html_e( 'Client Sync needs a few pages to function correctly for your clients. We can create these for you automatically.', 'client-sync' ); ?></p>
		<p class="description">
			<?php esc_html_e( 'This will create the following pages with the appropriate shortcodes:', 'client-sync' ); ?>
		</p>
		<ul class="ul-disc" style="margin-left: 1.5em;">
			<?php foreach ( $pages_to_create as $page_def ) : ?>
				<li>
					<strong><?php echo esc_html( $page_def['title'] ); ?></strong>
					<?php if ( ! empty( $page_def['content'] ) && str_starts_with( $page_def['content'], '[' ) ) : ?>
						&mdash; <code><?php echo esc_html( $page_def['content'] ); ?></code>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<form method="post">
			<input type="hidden" name="clisyc_wizard_nonce" value="<?php echo esc_attr( wp_create_nonce( 'clisyc_wizard_action' ) ); ?>">
			<button type="submit" class="button button-primary button-hero">
				<?php esc_html_e( 'Create Pages & Configure', 'client-sync' ); ?>
			</button>
			<br>
			<a href="<?php echo esc_url( $this->get_next_step_url() ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Skip this step', 'client-sync' ); ?>
			</a>
		</form>
		<?php
	}

	private function render_step_finish() {
		?>
		<h2><?php esc_html_e( 'Basics Complete!', 'client-sync' ); ?></h2>
		<p><?php esc_html_e( 'The essential components are now in place. Click the button below to continue to the main setup checklist.', 'client-sync' ); ?></p>
		<p><?php esc_html_e( 'Here, you can set your availability, configure notifications, and review optional features.', 'client-sync' ); ?></p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-guide' ) ); ?>" class="button button-primary button-hero">
			<?php esc_html_e( 'Go to Setup Checklist', 'client-sync' ); ?>
		</a>
		<?php
	}

	private function process_template_install( string $template_file_path ) {
		$json_data   = file_get_contents( $template_file_path ); // phpcs:ignore
		$data        = json_decode( $json_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			set_transient( 'clisyc_testing_feedback', [ 'error' => 'Template file contains invalid JSON.' ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			return;
		}

		$import_data = $data[0] ?? null;

		if ( ! is_array( $import_data ) ) {
			set_transient( 'clisyc_testing_feedback', [ 'error' => 'Template file is not in the correct format.' ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
			return;
		}
		
		// *** REFACTORED ***: Use new namespace
		$importer = new \DependentMedia\ClientSync\Services\Importer();
		$importer->install_from_data( $import_data );

		$feedback_message = __( 'Configuration imported successfully.', 'client-sync' );
		if ( ! empty( $import_data['template_meta']['title'] ) ) {
			$feedback_message .= '<br/>' . sprintf( '<strong>%s:</strong> %s', __( 'Template', 'client-sync' ), esc_html( $import_data['template_meta']['title'] ) );
		}
		set_transient( 'clisyc_testing_feedback', [ 'message' => $feedback_message ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
	}

	public function wipe_all_dimension_data() {
		global $wpdb;
        // *** REFACTORED ***: Use new option name
		$custom_types    = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		$all_plugin_cpts = is_array( $custom_types ) ? array_keys( $custom_types ) : [];
        $all_plugin_cpts[] = Constants::POST_TYPE_APPOINTMENT; // Also ensure appointments are cleared if this is called alone

        // Also wipe non-dimension plugin CPTs managed by Pro modules.
        $extra_cpts = [ 'clisyc_member_plan', 'clisyc_output_tmpl' ];
        foreach ( $extra_cpts as $extra_cpt ) {
            if ( post_type_exists( $extra_cpt ) && ! in_array( $extra_cpt, $all_plugin_cpts, true ) ) {
                $all_plugin_cpts[] = $extra_cpt;
            }
        }

		foreach ( $all_plugin_cpts as $cpt_slug ) {
			if ( empty( $cpt_slug ) || strpos( $cpt_slug, 'clisyc_' ) !== 0 ) {
				continue;
			}
			$existing_posts = get_posts( [ 'post_type' => $cpt_slug, 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids' ] );
			foreach ( $existing_posts as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}

        // *** REFACTORED ***: Use new option names
		delete_option( Constants::OPTION_DIMENSION_REGISTRY );
		delete_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES );
		delete_option( Constants::OPTION_GRAPH_VISUAL_STATE );
		delete_option( Constants::OPTION_DIMENSION_FIELDS );

        // *** REFACTORED ***: Use new namespace
		$db_manager = new \DependentMedia\ClientSync\Core\Database_Manager();
		$relationships_tbl = $db_manager->get_relationships_table_name();
		$graph_nodes_tbl = $wpdb->prefix . 'clisyc_graph_nodes';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "TRUNCATE TABLE {$relationships_tbl}" ); 
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "TRUNCATE TABLE {$graph_nodes_tbl}" );
	}

	private function get_next_step_url() {
		$keys            = array_keys( $this->steps );
		$current_index   = array_search( $this->step, $keys, true );
		$next_step_key   = $keys[ $current_index + 1 ] ?? 'finish';
		return add_query_arg( 'step', $next_step_key, admin_url( 'admin.php?page=clisyc-setup' ) );
	}

	private function process_create_pages() {
		$pages = self::get_page_definitions();

		foreach ( $pages as $slug => $page_data ) {
			$existing_page = get_page_by_path( $slug );
			$page_id       = $existing_page ? $existing_page->ID : 0;

			if ( ! $page_id ) {
				$page_id = wp_insert_post(
					[
						'post_title'   => $page_data['title'],
						'post_content' => $page_data['content'],
						'post_status'  => 'publish',
						'post_type'    => 'page',
						'post_name'    => $slug,
					]
				);

				if ( is_wp_error( $page_id ) ) {
					Debug_Logger::log( 'Failed to create page "' . $slug . '": ' . $page_id->get_error_message(), 'Onboarding' );
					continue;
				}

				if ( 'appointment-details' === $slug ) {
					update_option( Constants::OPTION_APPOINTMENT_VIEW_SLUG, $slug );
				}
			}

			// Auto-wire: set the corresponding option so the "Configure Frontend Pages" step auto-completes.
			if ( $page_id > 0 && ! empty( $page_data['option_key'] ) ) {
				update_option( $page_data['option_key'], $page_id );
			}
		}
	}

	/**
	 * Central registry of all page definitions.
	 * Used by the wizard (bulk create) and the AJAX single-page creator.
	 *
	 * @return array<string, array{title: string, content: string, option_key: string}>
	 */
	public static function get_page_definitions(): array {
		return [
			'booking-calendar'    => [
				'title'      => __( 'Booking Calendar', 'client-sync' ),
				'content'    => '[clisyc_booking_form]',
				'option_key' => Constants::OPTION_BOOKING_PAGE_ID,
			],
			'my-account'          => [
				'title'      => __( 'My Account', 'client-sync' ),
				'content'    => '[clisyc_user_account]',
				'option_key' => '',
			],
			'appointment-details' => [
				'title'      => __( 'Appointment Details', 'client-sync' ),
				'content'    => '[clisyc_appointment_detail]',
				'option_key' => Constants::OPTION_APPOINTMENT_VIEW_PAGE,
			],
			'booking-confirmed'   => [
				'title'      => __( 'Booking Confirmed', 'client-sync' ),
				'content'    => '[clisyc_booking_confirmation]',
				'option_key' => Constants::OPTION_BOOKING_SUCCESS_PAGE,
			],
			'search-results'      => [
				'title'      => __( 'Search Results', 'client-sync' ),
				'content'    => '[clisyc_search_results]',
				'option_key' => Constants::OPTION_SEARCH_RESULTS_PAGE,
			],
			'edit-appointment'    => [
				'title'      => __( 'Edit Appointment', 'client-sync' ),
				'content'    => '[clisyc_manager_edit_appointment]',
				'option_key' => Constants::OPTION_MANAGER_EDIT_PAGE,
			],
			'contact'             => [
				'title'      => __( 'Contact Us', 'client-sync' ),
				'content'    => '<!-- wp:paragraph --><p>' . __( 'We\'d love to hear from you. Please reach out using the information below or submit the contact form.', 'client-sync' ) . '</p><!-- /wp:paragraph -->',
				'option_key' => Constants::OPTION_CONTACT_PAGE,
			],
		];
	}
}