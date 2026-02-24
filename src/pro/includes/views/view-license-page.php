<?php
/**
 * File: src/pro/includes/views/view-license-page.php -> client-sync-pro/includes/views/view-license-page.php
 * View template for the Client Sync Pro license management page.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Variables passed from License_Manager::render_license_page():
 * @var string $license_key
 * @var string $license_status
 * @var string $expires
 */
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Client Sync Pro License', 'client-sync-pro' ); ?></h1>
    <?php
    // THE FIX: Prefix the variables with clisyc_pro_ to prevent naming collisions
    $clisyc_pro_notice = get_transient( 'clisyc_pro_admin_notice' );
    
    if ( $clisyc_pro_notice ) {
        // Determine class based on status for better feedback
        $clisyc_pro_notice_class = ( 'Active' === ( get_option( \ClientSyncPro\License_Manager::OPTION_NAME, [] )['status'] ?? '' ) ) ? 'notice-success' : 'notice-error';
        
        echo '<div class="notice ' . esc_attr( $clisyc_pro_notice_class ) . ' is-dismissible"><p>' . esc_html( $clisyc_pro_notice ) . '</p></div>';
        
        delete_transient( 'clisyc_pro_admin_notice' );
    }
    ?>
    <div class="postbox">
        <div class="inside">
            <p><?php esc_html_e( 'Enter your license key to activate Client Sync Pro and unlock advanced features like Resource Scheduling and the Form Builder.', 'client-sync-pro' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="clisyc_pro_license_key"><?php esc_html_e( 'License Key', 'client-sync-pro' ); ?></label></th>
                            <td>
                                <input name="clisyc_pro_license_key" id="clisyc_pro_license_key" type="text" value="<?php echo esc_attr( $license_key ); ?>" class="regular-text" <?php echo ( 'Active' === $license_status ) ? 'readonly' : ''; ?>>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Status', 'client-sync-pro' ); ?></th>
                            <td>
                                <?php if ( 'Active' === $license_status ) : ?>
                                    <span style="color: #4CAF50; font-weight: bold;"><?php esc_html_e( 'Active', 'client-sync-pro' ); ?></span>
                                    <?php if ( $expires ) : ?>
                                        <p class="description"><?php printf( 'Expires on: %s', esc_html( $expires ) ); ?></p>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span style="color: #d63638; font-weight: bold;"><?php esc_html_e( 'Inactive', 'client-sync-pro' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php wp_nonce_field( 'clisyc_pro_license_nonce' ); ?>
                <?php if ( 'Active' === $license_status ) : ?>
                    <input type="hidden" name="action" value="clisyc_pro_deactivate_license">
                    <?php submit_button( __( 'Deactivate License', 'client-sync-pro' ), 'delete' ); ?>
                <?php else : ?>
                    <input type="hidden" name="action" value="clisyc_pro_activate_license">
                    <?php submit_button( __( 'Activate License', 'client-sync-pro' ) ); ?>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>