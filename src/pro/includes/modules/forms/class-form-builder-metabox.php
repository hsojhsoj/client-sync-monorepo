<?php
/**
 * File: src/pro/includes/modules/forms/class-form-builder-metabox.php
 * Handles the meta boxes for the Form Builder UI.
 *
 * FINAL FIX: Corrected the script dependency handle from 'wp-media-editor' to the
 * proper WordPress core handle 'media-editor'.
 * MODIFIED: Added a "User Account" field type to the builder.
 * UPDATE: Updated license page slug in locked metabox message.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Forms
 */

namespace ClientSyncPro\Modules\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

class Form_Builder_Metabox {
    
    public function register_hooks() {
        add_action( 'add_meta_boxes_clisyc_form', [ $this, 'register_meta_boxes' ] );
        add_action( 'save_post_clisyc_form', [ $this, 'save_meta_data' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_builder_assets' ] );
    }

    public function enqueue_builder_assets( $hook_suffix ) {
        if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        $screen = get_current_screen();

        if ( ! is_object($screen) || 'clisyc_form' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_media();
        global $post;

        $asset_file_path = clisyc_PLUGIN_DIR . 'assets/dist/pro-forms/index.asset.php';
        if ( ! file_exists( $asset_file_path ) ) {
            return;
        }

        $asset_file = include $asset_file_path;

        $dependencies = $asset_file['dependencies'] ?? [];
        if ( ! in_array( 'wp-element', $dependencies ) ) {
            $dependencies[] = 'wp-element';
        }

        $dependencies[] = 'media-editor';

        wp_enqueue_script(
            'client-sync-pro-form-builder-app',
            clisyc_PLUGIN_URL . 'assets/dist/pro-forms/index.js',
            $dependencies,
            $asset_file['version'],
            true
        );

        wp_enqueue_style(
            'client-sync-pro-form-builder-style',
            clisyc_PLUGIN_URL . 'assets/dist/pro-forms/style-index.css',
            [ 'wp-components' ],
            $asset_file['version']
        );
        
        $form_json = get_post_meta( $post->ID, '_clisyc_form_builder_json', true );
        wp_localize_script( 'client-sync-pro-form-builder-app', 'clisycFormBuilderData', [
            'formJson' => $form_json ?: '[]',
            'fieldTypes' => $this->get_available_field_types(),
        ]);
    }

    public function register_meta_boxes() {
        // Only show the builder UI if the license is active.
        if ( ! function_exists('clisyc_pro_is_license_active') || ! clisyc_pro_is_license_active() ) {
            add_meta_box( 'clisyc-form-builder-locked', __( 'Form Builder', 'client-sync-pro' ), [ $this, 'render_locked_metabox' ], 'clisyc_form', 'normal', 'high' );
            return;
        }

        add_meta_box( 'clisyc-form-builder', __( 'Form Builder', 'client-sync-pro' ), [ $this, 'render_form_builder_metabox' ], 'clisyc_form', 'normal', 'high' );
        add_meta_box( 'clisyc-form-settings', __( 'Form Settings', 'client-sync-pro' ), [ $this, 'render_form_settings_metabox' ], 'clisyc_form', 'side', 'default' );
    }

    public function render_form_builder_metabox( \WP_Post $post ) {
        echo '<div id="clisyc-form-builder-app-root"><p><em>' . esc_html__('Loading Form Builder...', 'client-sync-pro') . '</em></p></div>';
        echo '<textarea id="clisyc-form-builder-json-output" name="_clisyc_form_builder_json" style="width: 100%; height: 150px; font-family: monospace; display: none;" readonly>' . esc_textarea( get_post_meta($post->ID, '_clisyc_form_builder_json', true) ) . '</textarea>';
    }
    
    public function render_form_settings_metabox( \WP_Post $post ) {
        wp_nonce_field( 'clisyc_form_settings_save', 'clisyc_form_settings_nonce' );
        $settings = get_post_meta( $post->ID, '_clisyc_form_settings', true );
        $settings = is_array($settings) ? $settings : [];
        $submission_action = $settings['submission_action'] ?? 'create_lead';
        $admin_email = $settings['admin_email'] ?? get_option('admin_email');
        $enable_responder = $settings['enable_responder'] ?? false;
        $responder_subject = $settings['responder_subject'] ?? 'Thank you for your submission';
        $responder_body = $settings['responder_body'] ?? 'We have received your message and will be in touch shortly.';
        ?>
        <p>
            <label for="clisyc_submission_action"><strong><?php esc_html_e( 'Submission Action', 'client-sync-pro' ); ?></strong></label><br>
            <select id="clisyc_submission_action" name="clisyc_form_settings[submission_action]" class="widefat">
                <option value="create_lead" <?php selected($submission_action, 'create_lead'); ?>><?php esc_html_e( 'Create a new Lead', 'client-sync-pro' ); ?></option>
                <option value="email_only" <?php selected($submission_action, 'email_only'); ?>><?php esc_html_e( 'Send email only (no lead created)', 'client-sync-pro' ); ?></option>
            </select>
        </p>
        <p>
            <label for="clisyc_admin_email"><strong><?php esc_html_e( 'Admin Notification Email', 'client-sync-pro' ); ?></strong></label><br>
            <input type="email" id="clisyc_admin_email" name="clisyc_form_settings[admin_email]" value="<?php echo esc_attr($admin_email); ?>" class="widefat">
        </p>
        <hr>
        <p>
            <label>
                <input type="checkbox" name="clisyc_form_settings[enable_responder]" value="1" <?php checked($enable_responder); ?>>
                <strong><?php esc_html_e( 'Enable Auto-Responder', 'client-sync-pro' ); ?></strong>
            </label>
        </p>
        <div class="clisyc-responder-fields">
            <p><label for="clisyc_responder_subject"><strong><?php esc_html_e( 'Responder Subject', 'client-sync-pro' ); ?></strong></label><br>
            <input type="text" id="clisyc_responder_subject" name="clisyc_form_settings[responder_subject]" value="<?php echo esc_attr($responder_subject); ?>" class="widefat"></p>
            <p><label><strong><?php esc_html_e( 'Responder Body', 'client-sync-pro' ); ?></strong></label></p>
            <?php
            wp_editor( $responder_body, 'clisyc_responder_body_editor', [
                'textarea_name' => 'clisyc_form_settings[responder_body]',
                'textarea_rows' => 8,
                'media_buttons' => false,
                'tinymce'       => true,
                'quicktags'     => true,
            ]);
            ?>
        </div>
        <?php
    }

    /**
     * Saves the metabox data.
     *
     * @param int      $post_id The ID of the post being saved.
     * @param \WP_Post $post    The post object.
     */
    public function save_meta_data( int $post_id, \WP_Post $post ) {
        if ( ! isset( $_POST['clisyc_form_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key($_POST['clisyc_form_settings_nonce']), 'clisyc_form_settings_save' ) ) { return; }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
        if ( 'clisyc_form' !== $post->post_type ) { return; }

        // --- FIX FOR LINE 144 (JSON DATA) ---
        if ( isset( $_POST['_clisyc_form_builder_json'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string is unslashed and validated as JSON before saving.
            $json_string = wp_unslash( $_POST['_clisyc_form_builder_json'] );
            
            // Basic validation that it is actually valid JSON
            json_decode($json_string);
            if (json_last_error() === JSON_ERROR_NONE) { 
                update_post_meta( $post_id, '_clisyc_form_builder_json', $json_string ); 
            }
        }
        
        // --- FIX FOR LINE 150 (SETTINGS ARRAY) ---
        if ( isset( $_POST['clisyc_form_settings'] ) && is_array( $_POST['clisyc_form_settings'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array is unslashed and then sanitized key-by-key below.
            $raw_settings = wp_unslash( $_POST['clisyc_form_settings'] );
            
            $sanitized_settings = [
                'submission_action' => in_array($raw_settings['submission_action'] ?? '', ['create_lead', 'email_only']) ? $raw_settings['submission_action'] : 'create_lead',
                'admin_email'       => sanitize_email($raw_settings['admin_email'] ?? ''),
                'enable_responder'  => !empty($raw_settings['enable_responder']),
                'responder_subject' => sanitize_text_field($raw_settings['responder_subject'] ?? ''),
                'responder_body'    => wp_kses_post($raw_settings['responder_body'] ?? ''),
            ];
            
            update_post_meta( $post_id, '_clisyc_form_settings', $sanitized_settings );
        }
    }

    private function get_available_field_types() {
        return [
            'user_account'   => ['label' => 'User Account', 'icon' => 'dashicons-admin-users'],
            'text'           => ['label' => 'Text', 'icon' => 'dashicons-editor-textcolor'],
            'textarea'       => ['label' => 'Text Area', 'icon' => 'dashicons-editor-alignleft'],
            'select'         => ['label' => 'Dropdown', 'icon' => 'dashicons-arrow-down-alt2'],
            'radio'          => ['label' => 'Radio Buttons', 'icon' => 'dashicons-marker'],
            'multi_checkbox' => ['label' => 'Checkboxes', 'icon' => 'dashicons-yes'],
            'image_map'      => ['label' => 'Image Map', 'icon' => 'dashicons-format-image'],
            'html'           => ['label' => 'Custom HTML', 'icon' => 'dashicons-editor-code'],
        ];
    }

    public function render_locked_metabox() {
        $license_url = admin_url( 'admin.php?page=clisyc-pro-license' );

        /* translators: %s: URL to the license activation page. */
        $message = sprintf( __( 'The Form Builder is a Pro feature. Please <a href="%s">activate your license</a> to begin building forms.', 'client-sync-pro' ), esc_url( $license_url ) );

        echo '<p>' . wp_kses_post( $message ) . '</p>';
    }
}