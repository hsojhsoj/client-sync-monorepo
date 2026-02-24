<?php
/**
 * File: src/shared/includes/admin/class-fullscreen-layout.php
 * Implements a MainWP-style full-screen admin layout for Client Sync.
 *
 * Hides the default WordPress admin sidebar and admin bar on Client Sync pages,
 * replacing them with a branded fixed header and custom left navigation sidebar.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fullscreen_Layout {

	/**
	 * Register WordPress hooks for the full-screen layout.
	 */
	public function register_hooks(): void {
		add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );
		add_action( 'admin_footer', [ $this, 'render_chrome' ], 5 );
	}

	/**
	 * Add 'clisyc-fullscreen' body class on Client Sync pages.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function add_body_class( string $classes ): string {
		if ( self::is_clisyc_page() ) {
			$classes .= ' clisyc-fullscreen ';
		}
		return $classes;
	}

	/**
	 * Output the custom header bar, sidebar, and help panel HTML.
	 * Runs in admin_footer so the DOM is fully built.
	 */
	public function render_chrome(): void {
		if ( ! self::is_clisyc_page() ) {
			return;
		}

		$this->render_header();
		$this->render_sidebar();
		$this->render_help_panel();
	}

	/**
	 * Render the fixed top header bar.
	 */
	private function render_header(): void {
		$current_title = $this->get_current_page_title();
		$admin_url     = admin_url();
		$user          = wp_get_current_user();
		$avatar        = get_avatar( $user->ID, 28 );
		?>
		<div id="clisyc-admin-header">
			<div class="clisyc-header-brand">
				<span class="dashicons dashicons-businessperson"></span>
				<span class="clisyc-header-brand-name"><?php esc_html_e( 'Client Sync', 'client-sync' ); ?></span>
			</div>

			<div class="clisyc-header-title">
				<?php echo esc_html( $current_title ); ?>
			</div>

			<button id="clisyc-sidebar-toggle" class="clisyc-header-toggle" aria-label="<?php esc_attr_e( 'Toggle navigation', 'client-sync' ); ?>">
				<span class="dashicons dashicons-menu"></span>
			</button>

			<div class="clisyc-header-right">
				<?php
				$help_tabs = Admin_Help::get_help_content( $this->get_current_help_slug() );
				if ( ! empty( $help_tabs ) ) :
				?>
				<button type="button" id="clisyc-help-toggle" class="clisyc-header-help-btn" aria-label="<?php esc_attr_e( 'Help', 'client-sync' ); ?>" aria-expanded="false" aria-controls="clisyc-help-panel">
					<span class="dashicons dashicons-editor-help"></span>
					<span class="clisyc-help-btn-label"><?php esc_html_e( 'Help', 'client-sync' ); ?></span>
				</button>
				<?php endif; ?>
				<a href="<?php echo esc_url( $admin_url ); ?>" class="clisyc-header-wp-link">
					<span class="dashicons dashicons-wordpress"></span>
					<?php esc_html_e( 'WP Admin', 'client-sync' ); ?>
				</a>
				<span class="clisyc-header-user">
					<?php echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar returns safe HTML. ?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the custom left navigation sidebar.
	 * Reads from the global $submenu['client-sync'] array which has
	 * already been ordered by Menu_Manager::modify_and_reorder_submenu().
	 */
	private function render_sidebar(): void {
		global $submenu;

		$menu_items = $submenu['client-sync'] ?? [];
		if ( empty( $menu_items ) ) {
			return;
		}

		$current_slug = $this->get_current_menu_slug();

		// Default dashicons for known menu items that don't have icons injected.
		$default_icons = [
			'client-sync'                          => 'dashicons-dashboard',
			'edit.php?post_type=clisyc_appointment' => 'dashicons-calendar-alt',
			'clisyc-calendars'                     => 'dashicons-calendar',
			'clisyc-available-slots-list'           => 'dashicons-list-view',
			'clisyc-dimensions'                    => 'dashicons-networking',
			'edit.php?post_type=clisyc_form'        => 'dashicons-feedback',
			'edit.php?post_type=clisyc_member_plan'  => 'dashicons-groups',
			'edit.php?post_type=clisyc_package'      => 'dashicons-archive',
			'edit.php?post_type=clisyc_output_tmpl'  => 'dashicons-media-document',
			'clisyc-custom-fields'                 => 'dashicons-forms',
			'clisyc-reports'                       => 'dashicons-chart-bar',
			'clisyc-settings'                      => 'dashicons-admin-settings',
			'client-sync-pro-license'              => 'dashicons-admin-network',
			'clisyc-testing'                       => 'dashicons-hammer',
		];

		?>
		<nav id="clisyc-admin-sidebar" role="navigation" aria-label="<?php esc_attr_e( 'Client Sync Navigation', 'client-sync' ); ?>">
			<ul class="clisyc-sidebar-menu">
				<?php foreach ( $menu_items as $item ) :
					$label = $item[0] ?? '';
					$cap   = $item[1] ?? 'manage_options';
					$slug  = $item[2] ?? '';
					$class = $item[4] ?? '';

					// Skip hidden items.
					if ( strpos( $class, 'clisyc-hidden-submenu-item' ) !== false ) {
						continue;
					}

					// Skip if user lacks the capability.
					if ( ! current_user_can( $cap ) ) {
						continue;
					}

					// Render separator.
					if ( $class === 'wp-menu-separator' || empty( $label ) ) {
						echo '<li class="clisyc-sidebar-separator"><hr /></li>';
						continue;
					}

					// Build the URL.
					$url = $this->build_menu_url( $slug );

					// Determine active state.
					$is_active   = ( $slug === $current_slug );
					$active_class = $is_active ? ' clisyc-sidebar-item-active' : '';

					// Build icon HTML.
					// CPT items already have icon HTML injected in Menu_Manager.
					$icon_html = '';
					if ( preg_match( '/<span\s+class="[^"]*(?:dashicons|fa-)[^"]*"/', $label ) ) {
						// Label already contains an icon span — extract it.
						// The label format from Menu_Manager is: <span class="dashicons ...">icon</span>Label Text
						// We'll use it as-is.
						$icon_html = ''; // Icon is embedded in label.
					} else {
						// Add a default icon for known slugs.
						$icon_class = $default_icons[ $slug ] ?? 'dashicons-admin-generic';
						$icon_html  = '<span class="dashicons ' . esc_attr( $icon_class ) . '"></span>';
					}
					?>
					<li class="clisyc-sidebar-item<?php echo esc_attr( $active_class ); ?>">
						<a href="<?php echo esc_url( $url ); ?>">
							<?php
							echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_attr above.
							echo wp_kses( $label, [
								'span' => [
									'class' => [],
									'style' => [],
								],
							] );
							?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Get the current page title for the header.
	 *
	 * @return string
	 */
	private function get_current_page_title(): string {
		global $title;

		if ( ! empty( $title ) ) {
			return $title;
		}

		$screen = get_current_screen();
		if ( $screen && ! empty( $screen->id ) ) {
			// For CPT list pages, use the post type label.
			if ( $screen->base === 'edit' && ! empty( $screen->post_type ) ) {
				$cpt = get_post_type_object( $screen->post_type );
				if ( $cpt ) {
					return $cpt->labels->name;
				}
			}
			// For single CPT edit pages.
			if ( $screen->base === 'post' && ! empty( $screen->post_type ) ) {
				$cpt = get_post_type_object( $screen->post_type );
				if ( $cpt ) {
					return sprintf(
						/* translators: %s: Post type singular name. */
						__( 'Edit %s', 'client-sync' ),
						$cpt->labels->singular_name
					);
				}
			}
		}

		return __( 'Client Sync', 'client-sync' );
	}

	/**
	 * Determine the current active menu slug for highlighting.
	 *
	 * @return string
	 */
	private function get_current_menu_slug(): string {
		$screen = get_current_screen();

		// For CPT pages (edit.php?post_type=xxx), the slug is the full URL.
		if ( $screen ) {
			$post_type = $screen->post_type ?? '';
			if ( ! empty( $post_type ) && ( $screen->base === 'edit' || $screen->base === 'post' ) ) {
				return 'edit.php?post_type=' . $post_type;
			}
		}

		// For custom admin pages, the slug is in $_GET['page'].
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for menu highlighting only.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! empty( $page ) ) {
			return $page;
		}

		return '';
	}

	/**
	 * Build the full admin URL for a menu item slug.
	 *
	 * @param string $slug The menu slug.
	 * @return string Full admin URL.
	 */
	private function build_menu_url( string $slug ): string {
		// CPT slugs are already full URLs like "edit.php?post_type=clisyc_appointment".
		if ( strpos( $slug, '.php' ) !== false ) {
			return admin_url( $slug );
		}

		// Custom page slugs need to be wrapped in admin.php?page=.
		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * Render the slide-down help panel.
	 *
	 * Outputs a WordPress-style contextual help panel that slides
	 * down from below the header bar when the Help button is clicked.
	 */
	private function render_help_panel(): void {
		$slug      = $this->get_current_help_slug();
		$help_tabs = Admin_Help::get_help_content( $slug );

		if ( empty( $help_tabs ) ) {
			return;
		}

		$sidebar = Admin_Help::get_help_sidebar( $slug );
		?>
		<div id="clisyc-help-panel" class="clisyc-help-panel" aria-hidden="true">
			<div class="clisyc-help-panel-inner">
				<div class="clisyc-help-tabs">
					<?php foreach ( $help_tabs as $index => $tab ) : ?>
						<button
							type="button"
							class="clisyc-help-tab-btn <?php echo 0 === $index ? 'clisyc-help-tab-active' : ''; ?>"
							data-tab="<?php echo esc_attr( $tab['id'] ); ?>"
							role="tab"
							aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>"
						>
							<?php echo esc_html( $tab['title'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
				<div class="clisyc-help-tab-panels">
					<?php foreach ( $help_tabs as $index => $tab ) : ?>
						<div
							class="clisyc-help-tab-panel <?php echo 0 === $index ? 'clisyc-help-tab-panel-active' : ''; ?>"
							id="clisyc-help-tab-<?php echo esc_attr( $tab['id'] ); ?>"
							role="tabpanel"
						>
							<?php
							echo wp_kses_post( $tab['content'] );
							?>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $sidebar ) : ?>
					<div class="clisyc-help-sidebar">
						<?php echo wp_kses_post( $sidebar ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the current page slug for help content lookup.
	 *
	 * Maps the current admin screen to a page slug recognized
	 * by Admin_Help::get_help_content().
	 *
	 * @return string The page slug.
	 */
	private function get_current_help_slug(): string {
		$screen = get_current_screen();

		// For CPT pages, return the edit.php?post_type=... format.
		if ( $screen ) {
			$post_type = $screen->post_type ?? '';
			if ( ! empty( $post_type ) && ( $screen->base === 'edit' || $screen->base === 'post' ) ) {
				return 'edit.php?post_type=' . $post_type;
			}
		}

		// For custom admin pages, use the page query param.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for help context only.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! empty( $page ) ) {
			return $page;
		}

		return '';
	}

	/**
	 * Detect whether the current admin screen is a Client Sync page.
	 * Mirrors the detection logic in Asset_Manager::enqueue_scripts().
	 *
	 * @return bool
	 */
	public static function is_clisyc_page(): bool {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		// Check post type.
		$post_type = $screen->post_type ?? '';
		if ( strpos( $post_type, 'clisyc_' ) === 0 || $post_type === Constants::POST_TYPE_APPOINTMENT ) {
			return true;
		}

		// Check screen ID / base for our custom pages.
		$screen_id = $screen->id ?? '';
		if ( strpos( $screen_id, 'client-sync' ) !== false || strpos( $screen_id, 'clisyc-' ) !== false ) {
			return true;
		}

		// Check for dimension edit pages.
		if ( $screen->base === 'post' && ! empty( $post_type ) ) {
			$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
			$enabled_dimensions = $registry['dimensions'] ?? [];
			if ( isset( $enabled_dimensions[ $post_type ] ) && ! empty( $enabled_dimensions[ $post_type ]['enabled'] ) ) {
				return true;
			}
		}

		return false;
	}
}
