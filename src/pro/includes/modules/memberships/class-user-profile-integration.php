<?php
/**
 * File: src/pro/includes/modules/memberships/class-user-profile-integration.php
 * Adds membership selection fields to the user profile screen.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Memberships
 */

namespace ClientSyncPro\Modules\Memberships;

if ( ! defined( 'ABSPATH' ) ) exit;

class User_Profile_Integration {

    public function register_hooks() {
        add_action( 'show_user_profile', [ $this, 'render_user_profile_fields' ] );
        add_action( 'edit_user_profile', [ $this, 'render_user_profile_fields' ] );
        add_action( 'personal_options_update', [ $this, 'save_user_profile_fields' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_user_profile_fields' ] );
    }

    public function render_user_profile_fields( \WP_User $user ) {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $plans = get_posts( [
            'post_type' => 'clisyc_member_plan',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ] );

        $current_plan_id = get_user_meta( $user->ID, '_clisyc_active_membership_plan_id', true );
        ?>
        <h3><?php esc_html_e( 'Client Sync Membership', 'client-sync-pro' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="clisyc_member_plan"><?php esc_html_e( 'Active Membership Plan', 'client-sync-pro' ); ?></label></th>
                <td>
                    <select name="_clisyc_active_membership_plan_id" id="clisyc_member_plan">
                        <option value="0"><?php esc_html_e( '-- None --', 'client-sync-pro' ); ?></option>
                        <?php foreach ( $plans as $plan ) : ?>
                            <option value="<?php echo esc_attr( $plan->ID ); ?>" <?php selected( $current_plan_id, $plan->ID ); ?>>
                                <?php echo esc_html( $plan->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Manually assign a membership plan to this user.', 'client-sync-pro' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Saves the membership plan selection when the user profile is updated.
     *
     * @param int $user_id The ID of the user being edited.
     */
    public function save_user_profile_fields( int $user_id ) {
        // 1. Permission Check: Only those who can manage options should change plans.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // 2. Nonce Verification: Check the standard WordPress profile nonce.
        // The nonce name on the user edit screen is 'update-user_{user_id}'.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
            return;
        }

        // 3. Data Sanitization and Saving
        if ( isset( $_POST['_clisyc_active_membership_plan_id'] ) ) {
            $plan_id = absint( wp_unslash( $_POST['_clisyc_active_membership_plan_id'] ) );
            update_user_meta( $user_id, '_clisyc_active_membership_plan_id', $plan_id );
        }
    }
}