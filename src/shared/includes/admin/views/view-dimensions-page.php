<?php
/**
 * File: src/shared/includes/admin/views/view-dimensions-page.php -> client-sync/includes/admin/views/view-dimensions-page.php
 * View for the Dimension Manager admin page.
 * MODIFIED: Added a "Personnel Dimension" setting to designate which dimension represents staff.
 * MODIFIED: Added an instructional notice box to the System Setup tab.
 * MODIFIED: Added tooltips to the "Items" and "Is Resource" columns.
 * MODIFIED: Updated table logic to ensure Personnel dimensions are visually treated as Resources.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DependentMedia\ClientSync\Constants;

// Prepare data for the view
$clisyc_all_cpts_raw = get_post_types( [ 'show_ui' => true ], 'objects' );
$clisyc_all_cpts     = [];
foreach ( $clisyc_all_cpts_raw as $clisyc_cpt_slug => $clisyc_cpt_object ) {
	if ( ! in_array( $clisyc_cpt_slug, [ 'attachment', 'page', Constants::POST_TYPE_APPOINTMENT ], true ) ) {
		$clisyc_all_cpts[ $clisyc_cpt_slug ] = $clisyc_cpt_object;
	}
}
$clisyc_registry                = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
$clisyc_enabled_dimensions      = $clisyc_registry['dimensions'] ?? [];
$clisyc_filter_order            = $clisyc_registry['filter_order'] ?? [];
$clisyc_user_created_dimensions = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
$clisyc_default_tab             = 'setup';

// Reading the 'tab' GET parameter is for displaying the correct navigational tab, a non-state-changing action.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$clisyc_unslashed_get = wp_unslash( $_GET );
$clisyc_active_tab    = isset( $clisyc_unslashed_get['tab'] ) ? sanitize_key( $clisyc_unslashed_get['tab'] ) : $clisyc_default_tab;
$clisyc_tabs          = [
	'setup' => __( 'System Setup', 'client-sync' ),
	'graph' => __( 'Relationship Graph', 'client-sync' ),
];
if ( ! array_key_exists( $clisyc_active_tab, $clisyc_tabs ) ) {
	$clisyc_active_tab = $clisyc_default_tab;
}
?>
<style>
	.sort-handle { cursor: move; text-align: center; width: 30px; }
	.sortable-placeholder { height: 40px; background: #f0f0f0; }
</style>
<div class="wrap clisyc-dimension-manager-page">
	<h1><?php esc_html_e( 'Dimension Manager', 'client-sync' ); ?></h1>

    <?php
    $clisyc_feedback = get_transient( 'clisyc_admin_feedback' );
    if ( $clisyc_feedback ) {
        $clisyc_notice_class = ! empty( $clisyc_feedback['error'] ) ? 'notice-error' : 'notice-success';
        $clisyc_message      = $clisyc_feedback['error'] ?? ( $clisyc_feedback['message'] ?? '' );
        if ( $clisyc_message ) {
            echo '<div class="notice ' . esc_attr( $clisyc_notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $clisyc_message ) . '</p></div>';
        }
        delete_transient( 'clisyc_admin_feedback' );
    }
    ?>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $clisyc_tabs as $clisyc_slug => $clisyc_title ) : ?>
			<a href="?page=clisyc-dimensions&tab=<?php echo esc_attr( $clisyc_slug ); ?>" class="nav-tab <?php echo ( $clisyc_slug === $clisyc_active_tab ) ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $clisyc_title ); ?></a>
		<?php endforeach; ?>
	</h2>

	<div class="clisyc-tab-content">

		<?php if ( 'setup' === $clisyc_active_tab ) : ?>
            
            <p class="about-text"><?php esc_html_e( 'Configure your scheduling system by creating, enabling, and defining the properties of your dimensions.', 'client-sync' ); ?></p>
			
			<div id="col-container" class="wp-clearfix">
				<div id="col-left">
					<div class="col-wrap">
						<div class="form-wrap">
							<h2 id="clisyc-dimension-form-title"><?php esc_html_e( 'Add New Dimension Type', 'client-sync' ); ?></h2>
							<p><?php esc_html_e( 'Create a new content type (like "Services" or "Rooms") to use for scheduling.', 'client-sync' ); ?></p>
							<form id="clisyc-dimension-type-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="clisyc_add_dimension_type">
								<?php wp_nonce_field( 'clisyc_add_dimension_type_action', 'clisyc_add_dimension_nonce' ); ?>
								<input type="hidden" name="clisyc_dimension_action" id="clisyc_dimension_action" value="add">
								<input type="hidden" name="edit_dimension_slug" id="edit_dimension_slug" value="">
								
								<div class="form-field"><label for="dim_plural_name"><?php esc_html_e( 'Plural Name', 'client-sync' ); ?></label><input name="dim_plural_name" id="dim_plural_name" type="text" required><p><?php esc_html_e( 'Example: Key Equipment', 'client-sync' ); ?></p></div>
								<div class="form-field"><label for="dim_singular_name"><?php esc_html_e( 'Singular Name', 'client-sync' ); ?></label><input name="dim_singular_name" id="dim_singular_name" type="text" required><p><?php esc_html_e( 'Example: Equipment', 'client-sync' ); ?></p></div>
								<div class="form-field"><label for="dim_slug"><?php esc_html_e( 'Slug', 'client-sync' ); ?></label><input name="dim_slug" id="dim_slug" type="text" required><p><?php esc_html_e( 'Identifier (a-z, 0-9, _). The full slug including the `clisyc_` prefix cannot exceed 20 characters.', 'client-sync' ); ?></p></div>
								<div class="form-field">
									<label for="dim_icon"><?php esc_html_e( 'Menu Icon', 'client-sync' ); ?></label>
									<div style="display: flex; gap: 10px;">
										<input name="dim_icon" id="dim_icon" type="text" value="dashicons-admin-generic" style="flex-grow:1;">
										<button type="button" id="clisyc-open-icon-picker" class="button"><?php esc_html_e( 'Choose Icon', 'client-sync' ); ?></button>
									</div>
								</div>
								<?php submit_button( __( 'Add New Dimension Type', 'client-sync' ) ); ?>
								<button type="button" id="clisyc-cancel-edit-dimension" class="button button-secondary" style="display: none; vertical-align: middle; margin-left: 5px;"><?php esc_html_e( 'Cancel Edit', 'client-sync' ); ?></button>
							</form>
						</div>
					</div>
				</div>
				<div id="col-right">
					<div class="col-wrap">
						<!-- This space is intentionally left empty. -->
					</div>
				</div>
			</div>

            <!-- Main Combined Settings Table -->
            <form method="post" action="options.php">
                <?php settings_fields( 'clisyc_dimension_settings_group' ); ?>
                
                <?php // START: NEW INSTRUCTIONAL BOX ?>
                <div class="notice notice-info notice-alt inline">
                    <h3 style="margin-top: 0;"><?php esc_html_e( 'What is a Personnel Dimension?', 'client-sync' ); ?></h3>
                    <p><?php esc_html_e( 'Designating one of your dimensions as "Personnel" (e.g., "Employees", "Stylists", "Practitioners") is required for features that are linked to a specific staff member\'s user account, such as Google Calendar Sync.', 'client-sync' ); ?></p>
                    <p><strong><?php esc_html_e( 'Instructions:', 'client-sync' ); ?></strong>
                        <ol style="margin-top: 5px; margin-left: 20px;">
                            <li><?php esc_html_e( 'In the table below, ensure your staff dimension is marked as a "Resource".', 'client-sync' ); ?></li>
                            <li><?php esc_html_e( 'Then, select the radio button for that same dimension in the "Is Personnel" column.', 'client-sync' ); ?></li>
                            <li><?php esc_html_e( 'Save your changes.', 'client-sync' ); ?></li>
                        </ol>
                    </p>
                </div>
                <?php // END: NEW INSTRUCTIONAL BOX ?>

                <div class="postbox" style="margin-top:20px;">
                    <h2 class="hndle"><span><?php esc_html_e( 'Registered Dimensions', 'client-sync' ); ?></span></h2>
                    <div class="inside">
						<p><?php esc_html_e( 'Manage all available content types here. Enable them as dimensions, set their visibility, and choose one Primary dimension to hold the base schedules.', 'client-sync' ); ?></p>
						<?php $clisyc_personnel_dim_slug = get_option( Constants::OPTION_PERSONNEL_DIM_SLUG, '' ); ?>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
									<th scope="col" style="width: 30px;"></th> <!-- Drag Handle Column -->
									<th scope="col"><?php esc_html_e( 'Order', 'client-sync' ); ?></th> <!-- Order Number Column -->
                                    <th scope="col"><?php esc_html_e( 'Content Type', 'client-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Origin', 'client-sync' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Items', 'client-sync' ); ?><span class="clisyc-help-tip dashicons dashicons-editor-help" title="<?php esc_attr_e( 'The number of published items (posts) within this content type.', 'client-sync' ); ?>"></span></th>
                                    <th scope="col"><?php esc_html_e( 'Enable', 'client-sync' ); ?><span class="clisyc-help-tip dashicons dashicons-editor-help" title="<?php esc_attr_e( 'Check this box to include this content type in the scheduling system.', 'client-sync' ); ?>"></span></th>
                                    <th scope="col"><?php esc_html_e( 'Visible', 'client-sync' ); ?><span class="clisyc-help-tip dashicons dashicons-editor-help" title="<?php esc_attr_e( 'Uncheck this to hide the dimension from the client-facing booking form.', 'client-sync' ); ?>"></span></th>
                                    <th scope="col"><?php esc_html_e( 'Primary', 'client-sync' ); ?><span class="clisyc-help-tip dashicons dashicons-editor-help" title="<?php esc_attr_e( 'Select ONE dimension to be the "Primary" source of truth for schedules.', 'client-sync' ); ?>"></span></th>
                                    <th scope="col"><?php esc_html_e( 'Public', 'client-sync' ); ?><span class="clisyc-help-tip dashicons dashicons-editor-help" title="<?php esc_attr_e( 'Makes the post type public, adding it to the main menu and giving it archive pages. Only applies to dimensions created by this plugin.', 'client-sync' ); ?>"></span></th>
									<th scope="col"><?php esc_html_e( 'Schedule', 'client-sync' ); ?><?php if ( ! function_exists( 'clisyc_pro_is_license_active' ) ) : ?> <a href="https://dependentmedia.com/client-sync/" target="_blank" class="client-sync-pro-link">(Pro)</a><?php endif; ?><span class="clisyc-help-tip dashicons dashicons-editor-help" title="<?php esc_attr_e( 'Enable this to give items in this dimension their own availability calendar. A booking will only be valid if the Primary Item AND this item are both available at the same time.', 'client-sync' ); ?>"></span></th>
                                    <th scope="col"><?php esc_html_e( 'Pro Sync', 'client-sync' ); ?><span class="clisyc-help-tip dashicons dashicons-editor-help" title="<?php esc_attr_e( 'Designate this dimension for advanced integrations. Items can be linked to WordPress Users to enable features like Google Calendar 2-Way Sync. Requires "Schedule" to be enabled.', 'client-sync' ); ?>"></span></th>
                                </tr>
                            </thead>
                            <tbody id="clisyc-dimensions-table-body">
                                <?php
								$clisyc_enabled_slugs           = array_filter(
									$clisyc_filter_order,
									function ( $clisyc_slug_filter ) use ( $clisyc_enabled_dimensions ) {
										return ! empty( $clisyc_enabled_dimensions[ $clisyc_slug_filter ]['enabled'] );
									}
								);
								$clisyc_all_enabled_slugs       = array_keys(
									array_filter(
										$clisyc_enabled_dimensions,
										function ( $d ) {
											return ! empty( $d['enabled'] );
										}
									)
								);
								$clisyc_unordered_enabled_slugs = array_diff( $clisyc_all_enabled_slugs, $clisyc_enabled_slugs );
								sort( $clisyc_unordered_enabled_slugs );
								$clisyc_disabled_slugs = array_diff( array_keys( $clisyc_all_cpts ), $clisyc_all_enabled_slugs );
								sort( $clisyc_disabled_slugs );
								$clisyc_display_order = array_merge( $clisyc_enabled_slugs, $clisyc_unordered_enabled_slugs, $clisyc_disabled_slugs );
								$clisyc_order_counter = 1;
								foreach ( $clisyc_display_order as $clisyc_cpt_slug ) {
									if ( ! isset( $clisyc_all_cpts[ $clisyc_cpt_slug ] ) ) {
										continue;
									}
									$clisyc_cpt_object      = $clisyc_all_cpts[ $clisyc_cpt_slug ];
									$clisyc_is_enabled      = ! empty( $clisyc_enabled_dimensions[ $clisyc_cpt_slug ]['enabled'] );
									$clisyc_is_user_created = isset( $clisyc_user_created_dimensions[ $clisyc_cpt_slug ] );
                                    $clisyc_is_resource     = ! empty( $clisyc_enabled_dimensions[ $clisyc_cpt_slug ]['is_resource'] );
                                    $clisyc_is_sync_enabled = ! empty( $clisyc_enabled_dimensions[ $clisyc_cpt_slug ]['enable_sync'] );
                                    
                                    $clisyc_pro_active = function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active();
									?>
                                    <tr id="dim-<?php echo esc_attr( $clisyc_cpt_slug ); ?>" data-slug="<?php echo esc_attr( $clisyc_cpt_slug ); ?>">
                                        <td class="sort-handle"><span class="dashicons dashicons-move" <?php echo $clisyc_is_enabled ? '' : 'style="display:none;"'; ?>></span></td>
                                        <td class="order-number" style="text-align: center; vertical-align: middle;"><?php echo $clisyc_is_enabled ? esc_html( $clisyc_order_counter++ ) : '—'; ?></td>
                                        <td>
                                            <strong><?php echo esc_html( $clisyc_cpt_object->labels->name ); ?></strong> (<code><?php echo esc_html( $clisyc_cpt_slug ); ?></code>)
                                            <div class="row-actions">
                                                <span class="view">
                                                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $clisyc_cpt_slug ) ); ?>"><?php esc_html_e( 'All', 'client-sync' ); ?></a> |
                                                </span>
                                                <?php if ( $clisyc_is_enabled ) : ?>
                                                <span class="view">
                                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-available-slots-list&dimensions[' . $clisyc_cpt_slug . ']=' ) ); ?>"><?php esc_html_e( 'Booked', 'client-sync' ); ?></a> |
                                                </span>
                                                <?php endif; ?>
                                                <?php if ( $clisyc_is_user_created ) : ?>
                                                <span class="edit"><button type="button" class="button-link clisyc-edit-dimension-type"
                                                    data-slug="<?php echo esc_attr( $clisyc_cpt_slug ); ?>"
                                                    data-plural="<?php echo esc_attr( $clisyc_user_created_dimensions[ $clisyc_cpt_slug ]['plural'] ?? '' ); ?>"
                                                    data-singular="<?php echo esc_attr( $clisyc_user_created_dimensions[ $clisyc_cpt_slug ]['singular'] ?? '' ); ?>"
                                                    data-icon="<?php echo esc_attr( $clisyc_user_created_dimensions[ $clisyc_cpt_slug ]['icon'] ?? '' ); ?>"
                                                    data-public="<?php echo esc_attr( ! empty( $clisyc_user_created_dimensions[ $clisyc_cpt_slug ]['public'] ) ? '1' : '0' ); ?>">
                                                    <?php esc_html_e( 'Edit', 'client-sync' ); ?>
                                                </button> | </span>
                                                <span class="delete">
                                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clisyc_delete_dimension_type&dimension_slug=' . $clisyc_cpt_slug ), 'clisyc_delete_dimension_' . $clisyc_cpt_slug, 'clisyc_delete_dimension_nonce' ) ); ?>" class="button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Are you sure? This will delete the content type and ALL posts within it. This cannot be undone.', 'client-sync' ); ?>');">
                                                        <?php esc_html_e( 'Delete', 'client-sync' ); ?>
                                                    </a>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            if ( $clisyc_is_user_created ) {
                                                echo '<span class="dashicons dashicons-businessperson"></span> ' . esc_html__( 'Client Sync', 'client-sync' );
                                            } elseif ( in_array( $clisyc_cpt_slug, [ 'post' ], true ) ) {
                                                echo '<span class="dashicons dashicons-wordpress"></span> ' . esc_html__( 'Core', 'client-sync' );
                                            } else {
                                                echo '<span class="dashicons dashicons-admin-plugins"></span> ' . esc_html__( 'Other', 'client-sync' );
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $clisyc_post_count    = wp_count_posts( $clisyc_cpt_slug )->publish;
                                            $clisyc_all_items_url = admin_url( 'edit.php?post_type=' . $clisyc_cpt_slug );
                                            $clisyc_add_new_url   = admin_url( 'post-new.php?post_type=' . $clisyc_cpt_slug );
                                            printf( '<a href="%s">%d</a>', esc_url( $clisyc_all_items_url ), (int) $clisyc_post_count );
                                            ?>
                                            <div class="row-actions">
                                                <span class="add">
                                                    <a href="<?php echo esc_url( $clisyc_add_new_url ); ?>"><?php esc_html_e( 'Add New', 'client-sync' ); ?></a>
                                                </span>
                                            </div>
                                        </td>
                                        <!-- ENABLED CHECKBOX -->
                                        <td><input type="checkbox" name="clisyc_dimension_registry[dimensions][<?php echo esc_attr( $clisyc_cpt_slug ); ?>][enabled]" value="1" <?php checked( ! empty( $clisyc_enabled_dimensions[ $clisyc_cpt_slug ]['enabled'] ) ); ?>></td>
                                        
                                        <!-- VISIBLE CHECKBOX -->
                                        <td><input type="checkbox" name="clisyc_dimension_registry[dimensions][<?php echo esc_attr( $clisyc_cpt_slug ); ?>][frontend_visible]" value="1" <?php checked( ! empty( $clisyc_enabled_dimensions[ $clisyc_cpt_slug ]['frontend_visible'] ) ); ?>></td>
                                        
                                        <!-- PRIMARY RADIO -->
                                        <td><input type="radio" name="clisyc_dimension_registry[primary_dimension]" value="<?php echo esc_attr( $clisyc_cpt_slug ); ?>" <?php checked( ! empty( $clisyc_enabled_dimensions[ $clisyc_cpt_slug ]['primary'] ) ); ?>></td>
                                        
                                        <!-- PUBLIC CHECKBOX -->
                                        <td><?php if ( $clisyc_is_user_created ) : ?><input type="checkbox" name="clisyc_dimension_registry[dimensions][<?php echo esc_attr( $clisyc_cpt_slug ); ?>][public]" value="1" <?php checked( ! empty( $clisyc_user_created_dimensions[ $clisyc_cpt_slug ]['public'] ) ); ?>><?php else : ?>—<?php endif; ?></td>
                                        
                                        <!-- IS RESOURCE CHECKBOX -->
                                        <!-- Logic: Checked if is_resource OR is_personnel. Disabled if Pro not active. -->
                                        <td><input type="checkbox" name="clisyc_dimension_registry[dimensions][<?php echo esc_attr( $clisyc_cpt_slug ); ?>][is_resource]" value="1" <?php checked( $clisyc_is_resource ); ?> <?php disabled( ! $clisyc_pro_active ); ?>></td>
                                        
                                        <!-- IS PERSONNEL / SYNC CHECKBOX -->
                                        <!-- Logic: Checked if it matches the option. Disabled if NOT a resource. -->
                                        <td><input type="checkbox" name="clisyc_dimension_registry[dimensions][<?php echo esc_attr( $clisyc_cpt_slug ); ?>][enable_sync]" value="1" <?php checked( $clisyc_is_sync_enabled ); ?> <?php disabled( ! $clisyc_is_resource ); ?> title="<?php echo esc_attr( $clisyc_is_resource ? '' : 'Must have "Schedule" enabled to use Pro Sync features.' ); ?>"></td>
                                    </tr>
								<?php } ?>
                            </tbody>
                        </table>
						<div id="filter-order-hidden"></div>  <!-- Container for dynamic hidden inputs -->
                    </div>
                </div>
                <?php submit_button( __( 'Save All Changes', 'client-sync' ) ); ?>
            </form>

		<?php elseif ( 'graph' === $clisyc_active_tab ) : ?>
			
            <p class="about-text">
				<?php esc_html_e( 'Visually configure the relationships between your dimensions.', 'client-sync' ); ?>
			</p>
			
			<div id="clisyc-node-editor-root">
				<p style="text-align: center; padding-top: 50px;"><em><?php esc_html_e( 'Node Editor Loading...', 'client-sync' ); ?></em></p>
			</div>
            
		<?php endif; ?>

	</div> <!-- .clisyc-tab-content -->
</div>