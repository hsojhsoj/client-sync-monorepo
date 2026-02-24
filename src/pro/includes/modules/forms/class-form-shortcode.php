<?php
/**
 * File: src/pro/includes/modules/forms/class-form-shortcode.php
 * Handles the rendering of the [clisyc_form] shortcode.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Forms
 */

namespace ClientSyncPro\Modules\Forms;

use DependentMedia\ClientSync\Services\FormRenderer;

if ( ! defined( 'ABSPATH' ) ) exit;

class Form_Shortcode {

    public function register_hooks() {
        add_shortcode( 'clisyc_form', [ $this, 'render_shortcode' ] );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts(
            [ 'id' => 0 ],
            $atts,
            'clisyc_form'
        );

        $form_id = absint( $atts['id'] );
        if ( ! $form_id ) {
            return '<!-- Client Sync Pro: Form ID not provided. -->';
        }

        $form_post = get_post( $form_id );
        if ( ! $form_post || 'clisyc_form' !== $form_post->post_type ) {
            return '<!-- Client Sync Pro: Invalid Form ID. -->';
        }

        // Enqueue assets needed for the form
        wp_enqueue_style( 'clisyc-frontend-style' );
        wp_enqueue_style( 'clisyc-image-marker' );
        wp_enqueue_script( 'clisyc-frontend-image-marker' );

        $form_json = get_post_meta( $form_id, '_clisyc_form_builder_json', true );
        $form_fields = json_decode( $form_json, true );

        if ( ! is_array( $form_fields ) ) {
            return '<!-- Client Sync Pro: Form structure is invalid. -->';
        }
        
        $form_renderer = new FormRenderer();
        ob_start();
        ?>
        <div id="clisyc-form-<?php echo esc_attr( $form_id ); ?>" class="clisyc-container clisyc-form-wrapper">
            <?php
            // Display feedback from submission
            $user_key      = is_user_logged_in() ? get_current_user_id() : md5( $_SERVER['REMOTE_ADDR'] ?? '0' );
            $transient_key = 'clisyc_form_fb_' . $form_id . '_' . $user_key;
            $feedback      = get_transient( $transient_key );
            if ( $feedback && is_array( $feedback ) ) {
                $type  = $feedback['type'] ?? 'info';
                $text  = $feedback['text'] ?? '';
                $class = ( 'error' === $type ) ? 'clisyc-error-message' : 'clisyc-success-message';
                echo '<div class="clisyc-registration-message ' . esc_attr( $class ) . '"><p>' . wp_kses_post( $text ) . '</p></div>';
                delete_transient( $transient_key );
            }
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="clisyc_form_submit">
                <input type="hidden" name="clisyc_form_id" value="<?php echo esc_attr( $form_id ); ?>">
                <?php wp_nonce_field( 'clisyc_form_submit_' . $form_id, 'clisyc_form_nonce' ); ?>

                <?php
                foreach ( $form_fields as $field_def ) {
                    // Logic for user account creation fields
                    if ( 'user_account' === $field_def['type'] ) {
                        if ( is_user_logged_in() ) {
                            echo '<p>' . esc_html__( 'You are already logged in.', 'client-sync-pro' ) . '</p>';
                            continue;
                        }
                        ?>
                        <fieldset class="clisyc-form-field clisyc-field-width-full">
                            <legend><h3><?php esc_html_e( 'Create Your Account', 'client-sync-pro' ); ?></h3></legend>
                            <div class="clisyc-form-field">
                                <label for="clisyc_user_email"><?php esc_html_e( 'Email Address', 'client-sync-pro' ); ?> <span class="required">*</span></label>
                                <input type="email" name="clisyc_user_email" id="clisyc_user_email" required>
                            </div>
                             <div class="clisyc-form-field">
                                <label for="clisyc_user_login"><?php esc_html_e( 'Username', 'client-sync-pro' ); ?> <span class="required">*</span></label>
                                <input type="text" name="clisyc_user_login" id="clisyc_user_login" required>
                            </div>
                            <div class="clisyc-form-field">
                                <label for="clisyc_user_pass"><?php esc_html_e( 'Password', 'client-sync-pro' ); ?> <span class="required">*</span></label>
                                <input type="password" name="clisyc_user_pass" id="clisyc_user_pass" required>
                            </div>
                            <div class="clisyc-form-field">
                                <label for="clisyc_user_pass_confirm"><?php esc_html_e( 'Confirm Password', 'client-sync-pro' ); ?> <span class="required">*</span></label>
                                <input type="password" name="clisyc_user_pass_confirm" id="clisyc_user_pass_confirm" required>
                            </div>
                        </fieldset>
                        <?php
                        continue;
                    }

                    $field_name_attr = 'clisyc_form_field_' . $field_def['id'];
                    $field_key       = $field_def['id']; 
                    $width_class     = 'clisyc-field-width-' . ($field_def['width'] ?? 'full');

                    $options_for_renderer = [];
                    if ( ! empty( $field_def['options'] ) && is_array( $field_def['options'] ) ) {
                         $options_for_renderer = array_map(fn($opt) => ['value' => $opt], $field_def['options']);
                    }
                    
                    /**
                     * The Connector: Extracting data from the JSON blob created by React Builder
                     * and passing it to the Shared Renderer.
                     */
                    $field_renderer_def = [
                        'label'        => $field_def['label'] ?? '',
                        'type'         => $field_def['type'] ?? 'text',
                        'required'     => ! empty( $field_def['required'] ),
                        'options'      => $options_for_renderer,
                        'html_content' => $field_def['html_content'] ?? '',
                        'image_id'     => $field_def['image_id'] ?? 0,    // Pass the ID
                        'image_url'    => $field_def['image_url'] ?? '',  // Pass the URL
                        'layout'       => $field_def['layout'] ?? 'vertical',
                    ];
                    
                    $form_renderer->render_frontend_custom_field(
                        $field_name_attr,
                        $field_renderer_def,
                        null,
                        'default',
                        $field_key,
                        $width_class
                    );
                }
                ?>
                <div class="clisyc-submit-button-wrapper" style="width: 100%; margin-top: 20px;">
                    <button type="submit" class="button button-primary clisyc-submit-button">
                        <?php esc_html_e( 'Submit', 'client-sync-pro' ); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}