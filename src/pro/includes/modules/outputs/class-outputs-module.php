<?php
/**
 * File: src/pro/includes/modules/outputs/class-outputs-module.php
 * Outputs Module: Manages dynamic output templates for post-booking communications.
 *
 * Registers the Output Templates CPT, admin UI, metaboxes, and integrates with notifications.
 * Pro-only; gated by license.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Outputs
 */

namespace ClientSyncPro\Modules\Outputs;

use ClientSyncPro\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Outputs_Module implements Module_Interface {

	/**
	 * Initializes the module by loading its components and registering their hooks.
	 * This method is called by the Module_Manager.
	 * {@inheritdoc}
	 */
	public function initialize() {
		require_once __DIR__ . '/class-output-renderer.php';
		require_once __DIR__ . '/class-output-template-channel.php';

		add_action( 'init', [ $this, 'register_cpt' ] );

		add_action( 'add_meta_boxes_clisyc_output_tmpl', [ $this, 'add_metaboxes' ] );
		add_action( 'save_post_clisyc_output_tmpl', [ $this, 'save_template' ] );
		add_filter( 'clisyc_notification_channels', [ $this, 'register_output_channel' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Template duplication.
		add_filter( 'post_row_actions', [ $this, 'add_duplicate_action' ], 10, 2 );
		add_action( 'admin_post_clisyc_duplicate_output_template', [ $this, 'handle_duplicate_template' ] );

		// Template preview page: registered under client-sync so WordPress keeps
		// the parent menu open. The menu manager's $desired_order allowlist
		// automatically hides it from the sidebar since the slug is not listed.
		add_action( 'admin_menu', [ $this, 'register_preview_page' ] );

		// Highlight "Output Templates" submenu when on preview page.
		add_filter( 'submenu_file', [ $this, 'fix_preview_submenu_highlight' ] );

		// Tab bar on the Output Templates list page.
		add_action( 'all_admin_notices', [ $this, 'inject_tab_bar_on_list_page' ] );
	}

	/**
	 * Register CPT for output templates.
	 */
	public function register_cpt() {
		$labels = [
			'name'          => __( 'Output Templates', 'client-sync-pro' ),
			'singular_name' => __( 'Output Template', 'client-sync-pro' ),
			'add_new'       => __( 'Add New Template', 'client-sync-pro' ),
			'add_new_item'  => __( 'Add New Output Template', 'client-sync-pro' ),
			'edit_item'     => __( 'Edit Output Template', 'client-sync-pro' ),
			'new_item'      => __( 'New Output Template', 'client-sync-pro' ),
			'view_item'     => __( 'View Output Template', 'client-sync-pro' ),
			'menu_name'     => __( 'Output Templates', 'client-sync-pro' ),
		];

		$args = [
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'client-sync',
			'rewrite'         => [ 'slug' => 'clisyc-output-tmpl' ],
			'capability_type' => 'post',
			'has_archive'     => false,
			'hierarchical'    => false,
			'supports'        => [ 'title' ],
			'menu_icon'       => 'dashicons-media-document',
		];

		register_post_type( 'clisyc_output_tmpl', $args );
	}

	/**
	 * Add JSON metabox for template layout.
	 */
	public function add_metaboxes() {
		if ( ! function_exists( 'clisyc_pro_is_license_active' ) || ! \clisyc_pro_is_license_active() ) {
			add_meta_box( 'clisyc-output-builder-locked', __( 'Template Builder', 'client-sync-pro' ), [ $this, 'render_locked_metabox' ], 'clisyc_output_tmpl', 'normal', 'high' );
			return;
		}

		add_meta_box(
			'clisyc_output_template_json',
			__( 'Template Builder', 'client-sync-pro' ),
			[ $this, 'render_metabox' ],
			'clisyc_output_tmpl',
			'normal',
			'high'
		);

		add_meta_box(
			'clisyc_output_triggers',
			__( 'Triggers & Channels', 'client-sync-pro' ),
			[ $this, 'render_triggers_metabox' ],
			'clisyc_output_tmpl',
			'side'
		);
	}

	/**
	 * Render main builder metabox (placeholder for React).
	 */
	public function render_metabox( $post ) {
		wp_nonce_field( 'clisyc_save_output_template', 'clisyc_output_nonce' );
		$json = get_post_meta( $post->ID, 'clisyc_template_json', true );
		?>
		<div id="clisyc-output-builder-root"></div> <!-- React mounts here -->
		<textarea id="clisyc-output-json-output" style="display:none;" name="clisyc_template_json"><?php echo esc_textarea( $json ); ?></textarea>
		<p><?php esc_html_e( 'Drag fields to build your layout. Preview updates live.', 'client-sync-pro' ); ?></p>
		<?php
	}

	/**
	 * Render triggers/channels metabox.
	 */
	public function render_triggers_metabox( $post ) {
		$triggers = get_post_meta( $post->ID, 'clisyc_triggers', true ) ?: [ 'appointment_complete' ];
		$channels = get_post_meta( $post->ID, 'clisyc_channels', true ) ?: [ 'email', 'pdf' ];
		$all_notification_events = ( new \DependentMedia\ClientSync\Core\Notifications() )->get_event_types();
		?>
		<p><label><strong><?php esc_html_e( 'Trigger Events:', 'client-sync-pro' ); ?></strong></label></p>
		<div style="max-height: 150px; overflow-y: auto; border: 1px solid #ddd; padding: 5px; background: #fff;">
			<?php foreach ( $all_notification_events as $event_key => $event_data ) : ?>
				<label style="display: block; margin-bottom: 5px;">
					<input type="checkbox" name="clisyc_triggers[]" value="<?php echo esc_attr( $event_key ); ?>" <?php checked( in_array( $event_key, $triggers, true ) ); ?>> 
					<?php echo esc_html( $event_data['label'] ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<p><label><strong><?php esc_html_e( 'Delivery Channels:', 'client-sync-pro' ); ?></strong></label></p>
		<ul>
			<li><label><input type="checkbox" name="clisyc_channels[]" value="email" <?php checked( in_array( 'email', $channels, true ) ); ?>> <?php esc_html_e( 'Send as Email Body', 'client-sync-pro' ); ?></label></li>
			<li><label><input type="checkbox" name="clisyc_channels[]" value="pdf" <?php checked( in_array( 'pdf', $channels, true ) ); ?>> <?php esc_html_e( 'Attach as PDF to Email', 'client-sync-pro' ); ?></label></li>
			<li><label><input type="checkbox" name="clisyc_channels[]" value="sms" <?php checked( in_array( 'sms', $channels, true ) ); ?>> <?php esc_html_e( 'Send as SMS (Summary)', 'client-sync-pro' ); ?></label></li>
		</ul>
		<?php
	}

	/**
	 * Save template data.
	 */
	public function save_template( $post_id ) {
		if ( ! isset( $_POST['clisyc_output_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clisyc_output_nonce'] ), 'clisyc_save_output_template' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save JSON
		if ( isset( $_POST['clisyc_template_json'] ) ) {
			update_post_meta( $post_id, 'clisyc_template_json', sanitize_textarea_field( wp_unslash( $_POST['clisyc_template_json'] ) ) );
		}

		// Save triggers/channels
		$triggers = isset( $_POST['clisyc_triggers'] ) && is_array( $_POST['clisyc_triggers'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['clisyc_triggers'] ) ) : [];
		update_post_meta( $post_id, 'clisyc_triggers', $triggers );

		$channels = isset( $_POST['clisyc_channels'] ) && is_array( $_POST['clisyc_channels'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['clisyc_channels'] ) ) : [];
		update_post_meta( $post_id, 'clisyc_channels', $channels );
	}

	/**
	 * Register as notification channel.
	 */
	public function register_output_channel( $channels ) {
		$channels['output_template'] = new Output_Template_Channel();
		return $channels;
	}

	/**
	 * Enqueue React builder assets.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! is_object( $screen ) || 'clisyc_output_tmpl' !== $screen->post_type ) {
			return;
		}
		
		$asset_file_path = clisyc_PLUGIN_DIR . 'assets/dist/pro-outputs/index.asset.php';
		if ( ! file_exists( $asset_file_path ) ) {
			return;
		}

		$asset_file = include $asset_file_path;
		$dependencies = $asset_file['dependencies'] ?? [];
		if ( ! in_array( 'wp-element', $dependencies, true ) ) {
			$dependencies[] = 'wp-element';
		}

		wp_enqueue_script(
			'clisyc-output-builder',
			clisyc_PLUGIN_URL . 'assets/dist/pro-outputs/index.js',
			$dependencies,
			$asset_file['version'],
			true
		);

		wp_localize_script( 'clisyc-output-builder', 'clisycOutputBuilderData', [
			'formJson'     => get_post_meta( get_the_ID(), 'clisyc_template_json', true ) ?: '[]',
			'fieldTypes'   => $this->get_available_fields(),
			'placeholders' => $this->get_all_placeholders(),
		] );

		wp_enqueue_style(
			'clisyc-output-builder-style',
			clisyc_PLUGIN_URL . 'assets/dist/pro-outputs/style-index.css',
			['wp-components'],
			$asset_file['version'] 
		);
	}

	/**
	 * Get available fields for the builder.
	 */
	private function get_available_fields() {
		$fields = [
			'client_name'      => [ 'label' => __( 'Client Name', 'client-sync-pro' ), 'icon' => 'dashicons-admin-users' ],
			'appointment_date' => [ 'label' => __( 'Appointment Date', 'client-sync-pro' ), 'icon' => 'dashicons-calendar' ],
			'service_type'     => [ 'label' => __( 'Service Type (Dimension)', 'client-sync-pro' ), 'icon' => 'dashicons-tag' ],
		];

		$custom_fields = get_option( 'clisyc_appointment_custom_fields', [] );
		foreach ( $custom_fields as $key => $field ) {
			$fields[ $key ] = [ 'label' => $field['label'], 'icon' => 'dashicons-text' ];
		}

		return $fields;
	}

	/**
	 * Adds a "Duplicate" action link to the Output Templates list table row actions.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    The current post object.
	 * @return array Modified actions with Duplicate link.
	 */
	public function add_duplicate_action( array $actions, \WP_Post $post ): array {
		if ( 'clisyc_output_tmpl' !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=clisyc_duplicate_output_template&post_id=' . $post->ID ),
			'clisyc_duplicate_output_template_' . $post->ID
		);

		$actions['duplicate'] = sprintf(
			'<a href="%s" title="%s">%s</a>',
			esc_url( $url ),
			esc_attr__( 'Duplicate this template', 'client-sync-pro' ),
			esc_html__( 'Duplicate', 'client-sync-pro' )
		);

		return $actions;
	}

	/**
	 * Handles the "Duplicate" action for Output Templates.
	 *
	 * Creates a copy of the template with all its meta (JSON, triggers, channels)
	 * and redirects to the new post's edit screen.
	 */
	public function handle_duplicate_template(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via wp_verify_nonce.
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_die( esc_html__( 'No template specified.', 'client-sync-pro' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'clisyc_duplicate_output_template_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'client-sync-pro' ) );
		}

		$original = get_post( $post_id );
		if ( ! $original || 'clisyc_output_tmpl' !== $original->post_type ) {
			wp_die( esc_html__( 'Invalid template.', 'client-sync-pro' ) );
		}

		// Create the duplicate post.
		$new_post_id = wp_insert_post( [
			'post_title'  => sprintf(
				/* translators: %s: Original template title. */
				__( '%s (Copy)', 'client-sync-pro' ),
				$original->post_title
			),
			'post_type'   => 'clisyc_output_tmpl',
			'post_status' => 'draft',
		] );

		if ( is_wp_error( $new_post_id ) ) {
			wp_die( esc_html__( 'Failed to create duplicate.', 'client-sync-pro' ) );
		}

		// Copy all relevant meta.
		$meta_keys = [ 'clisyc_template_json', 'clisyc_triggers', 'clisyc_channels' ];
		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value && false !== $value ) {
				update_post_meta( $new_post_id, $key, $value );
			}
		}

		// Redirect to the new template's edit screen.
		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_post_id ) );
		exit;
	}

	/**
	 * Builds a structured array of all available placeholders for the React builder.
	 *
	 * Collects all unique placeholders from every notification event type, deduplicates them,
	 * and returns them with human-readable labels and category groupings.
	 *
	 * @return array Array of placeholder objects with tag, label, and group keys.
	 */
	private function get_all_placeholders(): array {
		$notifications = new \DependentMedia\ClientSync\Core\Notifications();
		$events        = $notifications->get_event_types();

		// Collect all unique placeholder tags across all events.
		$all_tags = [];
		foreach ( $events as $event_data ) {
			foreach ( $event_data['placeholders'] ?? [] as $tag ) {
				$all_tags[ $tag ] = true;
			}
		}

		$label_map = [
			// Site.
			'{site_name}'                    => [ 'Site Name', 'Site' ],
			'{site_url}'                     => [ 'Site URL', 'Site' ],
			// Client.
			'{client_id}'                    => [ 'Client ID', 'Client' ],
			'{client_name}'                  => [ 'Full Name', 'Client' ],
			'{client_email}'                 => [ 'Email Address', 'Client' ],
			'{client_username}'              => [ 'Username', 'Client' ],
			'{client_first_name}'            => [ 'First Name', 'Client' ],
			'{client_last_name}'             => [ 'Last Name', 'Client' ],
			'{client_display_name}'          => [ 'Display Name', 'Client' ],
			'{phone_number}'                 => [ 'Phone Number', 'Client' ],
			// Appointment.
			'{appointment_id}'               => [ 'Appointment ID', 'Appointment' ],
			'{appointment_date}'             => [ 'Date (Raw)', 'Appointment' ],
			'{appointment_date_formatted}'   => [ 'Date (Formatted)', 'Appointment' ],
			'{appointment_time}'             => [ 'Time', 'Appointment' ],
			'{appointment_time_slot}'        => [ 'Time Slot (UTC)', 'Appointment' ],
			'{appointment_duration}'         => [ 'Duration (Minutes)', 'Appointment' ],
			'{appointment_notes}'            => [ 'Notes', 'Appointment' ],
			'{appointment_edit_link_admin}'  => [ 'Admin Edit Link', 'Appointment' ],
			'{appointment_view_link}'        => [ 'Client View Link', 'Appointment' ],
			// Payment.
			'{order_id}'                     => [ 'Order ID', 'Payment' ],
			'{order_total}'                  => [ 'Order Total', 'Payment' ],
			'{order_link_admin}'             => [ 'Admin Order Link', 'Payment' ],
			'{order_payment_method_title}'   => [ 'Payment Method', 'Payment' ],
			'{failure_reason}'               => [ 'Failure Reason', 'Payment' ],
		];

		$placeholders = [];
		foreach ( array_keys( $all_tags ) as $tag ) {
			if ( isset( $label_map[ $tag ] ) ) {
				$placeholders[] = [
					'tag'   => $tag,
					'label' => $label_map[ $tag ][0],
					'group' => $label_map[ $tag ][1],
				];
			} elseif ( 0 === strpos( $tag, '{appointment_field_' ) ) {
				// Dynamic custom appointment fields.
				$field_key = str_replace( [ '{appointment_field_', '}' ], '', $tag );
				$placeholders[] = [
					'tag'   => $tag,
					'label' => ucwords( str_replace( '_', ' ', $field_key ) ),
					'group' => 'Custom Fields',
				];
			} else {
				// Catch-all for any unknown placeholders (e.g., from filters).
				$label = ucwords( str_replace( [ '{', '}', '_' ], [ '', '', ' ' ], $tag ) );
				$placeholders[] = [
					'tag'   => $tag,
					'label' => $label,
					'group' => 'Other',
				];
			}
		}

		// Sort by group then label for consistent display.
		usort( $placeholders, function ( $a, $b ) {
			$group_cmp = strcmp( $a['group'], $b['group'] );
			return 0 !== $group_cmp ? $group_cmp : strcmp( $a['label'], $b['label'] );
		} );

		return $placeholders;
	}

	/**
	 * Registers the Template Preview page under the Client Sync menu.
	 *
	 * Registered as a real child of 'client-sync' so WordPress keeps the
	 * parent menu open. The visible menu entry is removed separately by
	 * hide_preview_from_submenu() so users navigate via the tab bar instead.
	 */
	public function register_preview_page(): void {
		add_submenu_page(
			'client-sync',
			__( 'Output Templates — Preview', 'client-sync-pro' ),
			__( 'Template Preview', 'client-sync-pro' ),
			'manage_options',
			'clisyc-template-preview',
			[ $this, 'render_preview_page' ]
		);
	}

	/**
	 * Highlights the "Output Templates" submenu item when viewing the preview page.
	 *
	 * @param string|null $submenu_file The current submenu file.
	 * @return string|null Modified submenu file.
	 */
	public function fix_preview_submenu_highlight( $submenu_file ) {
		global $plugin_page;

		if ( 'clisyc-template-preview' === ( $plugin_page ?? '' ) ) {
			return 'edit.php?post_type=clisyc_output_tmpl';
		}
		return $submenu_file;
	}

	/**
	 * Injects a tab bar above the Output Templates CPT list table.
	 *
	 * Hooks into `all_admin_notices` which fires right after the page title on edit.php pages.
	 */
	public function inject_tab_bar_on_list_page(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-clisyc_output_tmpl' !== $screen->id ) {
			return;
		}

		$list_url    = admin_url( 'edit.php?post_type=clisyc_output_tmpl' );
		$preview_url = admin_url( 'admin.php?page=clisyc-template-preview' );
		?>
		<h2 class="nav-tab-wrapper" style="margin-bottom: 1em;">
			<a href="<?php echo esc_url( $list_url ); ?>" class="nav-tab nav-tab-active">
				<?php esc_html_e( 'All Templates', 'client-sync-pro' ); ?>
			</a>
			<a href="<?php echo esc_url( $preview_url ); ?>" class="nav-tab">
				<?php esc_html_e( 'Preview', 'client-sync-pro' ); ?>
			</a>
		</h2>
		<?php
	}

	/**
	 * Renders the consolidated Template Preview page with sample data.
	 */
	public function render_preview_page(): void {
		$templates = get_posts( [
			'post_type'      => 'clisyc_output_tmpl',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		// Build a lookup of trigger keys → human-readable labels.
		$all_notification_events = ( new \DependentMedia\ClientSync\Core\Notifications() )->get_event_types();
		$trigger_labels = [];
		foreach ( $all_notification_events as $event_key => $event_data ) {
			$trigger_labels[ $event_key ] = $event_data['label'];
		}

		$list_url    = admin_url( 'edit.php?post_type=clisyc_output_tmpl' );
		$preview_url = admin_url( 'admin.php?page=clisyc-template-preview' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Output Templates', 'client-sync-pro' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper" style="margin-bottom: 1em;">';
		echo '<a href="' . esc_url( $list_url ) . '" class="nav-tab">' . esc_html__( 'All Templates', 'client-sync-pro' ) . '</a>';
		echo '<a href="' . esc_url( $preview_url ) . '" class="nav-tab nav-tab-active">' . esc_html__( 'Preview', 'client-sync-pro' ) . '</a>';
		echo '</h2>';
		echo '<p class="description">' . esc_html__( 'Preview all output templates with sample data. Edit a template to customize its content.', 'client-sync-pro' ) . '</p>';

		if ( empty( $templates ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No output templates found. Create one to see a preview here.', 'client-sync-pro' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<div class="clisyc-preview-grid">';
		foreach ( $templates as $template ) {
			$renderer     = Output_Renderer::with_sample_data( $template->ID );
			$preview_html = $renderer->render_html();
			$triggers     = get_post_meta( $template->ID, 'clisyc_triggers', true ) ?: [];
			$channels     = get_post_meta( $template->ID, 'clisyc_channels', true ) ?: [];
			$edit_url     = get_edit_post_link( $template->ID, 'raw' );
			$title        = $template->post_title ?: __( '(Untitled)', 'client-sync-pro' );
			$is_draft     = ( 'draft' === $template->post_status );

			echo '<div class="clisyc-preview-card' . ( $is_draft ? ' clisyc-preview-card--draft' : '' ) . '">';
			echo '<div class="clisyc-preview-card-header">';
			echo '<h3>' . esc_html( $title );
			if ( $is_draft ) {
				echo ' <span class="clisyc-preview-draft-badge">' . esc_html__( 'Draft', 'client-sync-pro' ) . '</span>';
			}
			echo '</h3>';
			echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Edit', 'client-sync-pro' ) . '</a>';
			echo '</div>';

			// Triggers & Channels meta row.
			echo '<div class="clisyc-preview-meta">';
			if ( ! empty( $triggers ) ) {
				$trigger_names = array_map( function ( $t ) use ( $trigger_labels ) {
					return $trigger_labels[ $t ] ?? ucwords( str_replace( '_', ' ', $t ) );
				}, $triggers );
				echo '<span class="clisyc-preview-tag clisyc-preview-tag--trigger"><span class="dashicons dashicons-bell" style="font-size:14px;width:14px;height:14px;"></span> ' . esc_html( implode( ', ', $trigger_names ) ) . '</span>';
			} else {
				echo '<span class="clisyc-preview-tag clisyc-preview-tag--none"><span class="dashicons dashicons-bell" style="font-size:14px;width:14px;height:14px;"></span> ' . esc_html__( 'No triggers', 'client-sync-pro' ) . '</span>';
			}
			if ( ! empty( $channels ) ) {
				echo '<span class="clisyc-preview-tag"><span class="dashicons dashicons-email" style="font-size:14px;width:14px;height:14px;"></span> ' . esc_html( implode( ', ', array_map( 'ucfirst', $channels ) ) ) . '</span>';
			}
			echo '</div>';

			echo '<div class="clisyc-preview-body">';
			echo wp_kses_post( $preview_html );
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the locked meta box if license is inactive.
	 */
	public function render_locked_metabox() {
		$license_url = admin_url( 'admin.php?page=clisyc-pro-license' );
		/* translators: %s: URL to the license activation page. */
		$message = sprintf( __( 'The Output Template Builder is a Pro feature. Please <a href="%s">activate your license</a> to use this functionality.', 'client-sync-pro' ), esc_url( $license_url ) );
		echo '<p>' . wp_kses_post( $message ) . '</p>';
	}
}