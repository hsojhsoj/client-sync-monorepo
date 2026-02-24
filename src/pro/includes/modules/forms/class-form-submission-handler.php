<?php
/**
 * File: src/pro/includes/modules/forms/class-form-submission-handler.php -> client-sync-pro/includes/modules/forms/class-form-submission-handler.php
 * Handles the server-side processing of form submissions.
 *
 * This class validates the submitted data, creates new 'clisyc_lead' users,
 * and sends out configured email notifications.
 * MODIFIED: Added logic to handle user account creation from the new form field.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Forms
 */

namespace ClientSyncPro\Modules\Forms;

use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

class Form_Submission_Handler {

    public function register_hooks() {
        add_action( 'admin_post_nopriv_clisyc_form_submit', [ $this, 'process_submission' ] );
        add_action( 'admin_post_clisyc_form_submit', [ $this, 'process_submission' ] );
    }

    public function process_submission() {
        // Rate limit unauthenticated submissions (5 per minute per IP).
        // Fail-closed: if the Security_Helper class is unavailable, block the submission.
        if ( ! is_user_logged_in() ) {
            if ( ! class_exists( '\DependentMedia\ClientSync\Utility\Security_Helper' ) ) {
                wp_die( esc_html__( 'Security module unavailable. Please try again later.', 'client-sync-pro' ), '', [ 'response' => 503 ] );
            }
            $rate_check = \DependentMedia\ClientSync\Utility\Security_Helper::check_rate_limit( 'form_submit', 5, 60 );
            if ( is_wp_error( $rate_check ) ) {
                wp_die( esc_html__( 'Too many submissions. Please try again later.', 'client-sync-pro' ), '', [ 'response' => 429 ] );
            }
        }

        $form_id = isset( $_POST['clisyc_form_id'] ) ? absint( $_POST['clisyc_form_id'] ) : 0;
        if ( ! $form_id ) { wp_die( esc_html__( 'Invalid request.', 'client-sync-pro' ) ); }
        
        if ( ! isset( $_POST['clisyc_form_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clisyc_form_nonce'] ), 'clisyc_form_submit_' . $form_id ) ) {
            $this->redirect_with_feedback( $form_id, 'error', 'Security check failed.' );
            return; // Defense-in-depth: redirect_with_feedback calls exit, but guard against mocks/tests.
        }

        $form_post = get_post( $form_id );
        if ( ! $form_post || 'clisyc_form' !== $form_post->post_type ) {
            $this->redirect_with_feedback( $form_id, 'error', 'Invalid form.' );
            return;
        }
        
        $settings = get_post_meta( $form_id, '_clisyc_form_settings', true );
        $form_fields = json_decode( get_post_meta( $form_id, '_clisyc_form_builder_json', true ), true );

        if ( ! is_array( $form_fields ) || empty( $form_fields ) ) {
            $this->redirect_with_feedback( $form_id, 'error', 'Form configuration error. Please contact the site administrator.' );
            return;
        }

        $sanitized_data = [];
        $validation_errors = [];
        $email_field_key = null;
        $unslashed_post = wp_unslash( $_POST );

        // START: NEW LOGIC FOR USER ACCOUNT HANDLING
        $user_account_field = null;
        foreach ($form_fields as $field) {
            if ('user_account' === $field['type']) {
                $user_account_field = $field;
                break;
            }
        }
        $new_user_id = null;

        if ($user_account_field && !is_user_logged_in()) {
            $user_email = sanitize_email($unslashed_post['clisyc_user_email'] ?? '');
            $user_login = sanitize_user($unslashed_post['clisyc_user_login'] ?? '', true);
            $user_pass = isset( $unslashed_post['clisyc_user_pass'] ) ? $unslashed_post['clisyc_user_pass'] : '';
            $user_pass_confirm = isset( $unslashed_post['clisyc_user_pass_confirm'] ) ? $unslashed_post['clisyc_user_pass_confirm'] : '';

            if (empty($user_email) || !is_email($user_email)) $validation_errors[] = 'Please provide a valid email address.';
            if (empty($user_login)) $validation_errors[] = 'Please enter a username.';
            if (empty($user_pass)) $validation_errors[] = 'Please enter a password.';
            if ( strlen( $user_pass ) < 8 ) $validation_errors[] = 'Password must be at least 8 characters.';
            if ($user_pass !== $user_pass_confirm) $validation_errors[] = 'Passwords do not match.';
            if (username_exists($user_login)) $validation_errors[] = 'This username is already taken.';
            if (email_exists($user_email)) $validation_errors[] = 'This email is already registered.';
            
            // If validation passes so far, attempt to create the user
            if (empty($validation_errors)) {
                $user_id = wp_insert_user([
                    'user_login' => $user_login,
                    'user_email' => $user_email,
                    'user_pass' => $user_pass,
                    'role' => 'subscriber', // Default role
                ]);
                
                if (is_wp_error($user_id)) {
                    Debug_Logger::log( 'User creation failed: ' . $user_id->get_error_message(), 'ProForms' );
                    $validation_errors[] = __( 'Could not create account. Please try again or contact the site administrator.', 'client-sync-pro' );
                } else {
                    $new_user_id = $user_id;
                }
            }
        }
        // END: NEW LOGIC

        foreach ( $form_fields as $field ) {
            if ($field['type'] === 'user_account') continue; // Skip user account field from data collection

            $field_post_key = 'clisyc_form_field_' . $field['id'];
            $value = $unslashed_post[$field_post_key] ?? null;

            if ( !empty($field['required']) && ( is_null($value) || $value === '' || (is_array($value) && empty($value)) ) ) {
                $validation_errors[] = sprintf( 'Field "%s" is required.', esc_html($field['label']) );
            }
            
            $data_key = $field['label'];
            if ( isset( $sanitized_data[ $data_key ] ) ) {
                $data_key = $field['label'] . ' (' . $field['id'] . ')';
            }
            if ( is_array( $value ) ) { $sanitized_data[ $data_key ] = implode( ', ', array_map( 'sanitize_text_field', $value ) ); }
            else { $sanitized_data[ $data_key ] = sanitize_text_field( $value ); }

            if ( !$email_field_key && (str_contains(strtolower($field['label']), 'email')) ) {
                $email_field_key = $field['label'];
            }
        }
        
        if ( !empty($validation_errors) ) {
            $this->redirect_with_feedback( $form_id, 'error', implode( '<br>', array_map( 'esc_html', $validation_errors ) ) );
            return;
        }
        
        // Associate custom field data with the new user
        if ($new_user_id) {
            foreach ($sanitized_data as $label => $value) {
                $meta_key = sanitize_key( str_replace(' ', '_', strtolower($label)) );
                update_user_meta($new_user_id, $meta_key, $value);
            }
        }
        
        // If a user was created, 'email_only' action doesn't make sense. We have a user now.
        // But if no user was created, fall back to the old lead creation system.
        if ( 'create_lead' === ($settings['submission_action'] ?? 'create_lead') ) {
            $this->create_lead( $form_post, $sanitized_data, $email_field_key, $new_user_id );
        }

        // Auto-login the new user BEFORE notifications so the session is
        // established even if send_notifications() fails.
        if ($new_user_id && !empty($user_account_field['autoLogin'])) {
            wp_set_current_user($new_user_id);
            wp_set_auth_cookie($new_user_id);
        }

        try {
            $this->send_notifications( $settings, $sanitized_data, $email_field_key );
        } catch ( \Throwable $e ) {
            Debug_Logger::log( 'Form notification error: ' . $e->getMessage(), 'ProForms' );
        }

        // Increment submission count for leads/emails.
        if ( 'create_lead' === ($settings['submission_action'] ?? 'create_lead') || 'email_only' === ($settings['submission_action'] ?? 'create_lead') ) {
            $count = (int) get_post_meta( $form_id, '_clisyc_form_submission_count', true );
            update_post_meta( $form_id, '_clisyc_form_submission_count', $count + 1 );
        }

        $this->redirect_with_feedback( $form_id, 'success', __( 'Thank you for your submission!', 'client-sync-pro' ) );
    }

    private function create_lead( \WP_Post $form_post, array $data, ?string $email_key, ?int $existing_user_id = null ) {
        $user_id = $existing_user_id;
        
        if ( !$user_id ) {
            if ( !$email_key || empty($data[$email_key]) || !is_email($data[$email_key]) ) { return; }
            $email = $data[$email_key];
            if ( email_exists( $email ) ) { return; }
            $user_id = wp_insert_user(['user_login' => $email, 'user_email' => $email, 'user_pass' => wp_generate_password(), 'role' => 'clisyc_lead']);
        }
        
        if ( is_wp_error( $user_id ) ) {
            Debug_Logger::log( 'Lead creation failed for form ' . $form_post->ID . ': ' . $user_id->get_error_message(), 'ProForms' );
            return;
        }

        // Store structured data for the list table
        $structured_data = [
            'form_id'   => $form_post->ID,
            'form_name' => $form_post->post_title,
            'fields'    => $data,
        ];
        update_user_meta( $user_id, '_clisyc_form_submission_data', $structured_data );
    }
    
    private function send_notifications( array $settings, array $data, ?string $email_key ) {
        $data_for_email = '<table border="0" cellpadding="5">';
        foreach ($data as $label => $value) { $data_for_email .= sprintf('<tr><td style="font-weight:bold;">%s:</td><td>%s</td></tr>', esc_html($label), esc_html($value)); }
        $data_for_email .= '</table>';
        
        $placeholders = ['{form_data}' => $data_for_email];
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if ( ! empty( $settings['admin_email'] ) && is_email( $settings['admin_email'] ) ) {
            $admin_subject = __( 'New Form Submission', 'client-sync-pro' );
            $admin_body    = __( 'A new form has been submitted.', 'client-sync-pro' ) . '<br><br>' . $data_for_email;
            wp_mail( $settings['admin_email'], $admin_subject, $admin_body, $headers );
        }

        if ( ! empty( $settings['enable_responder'] ) && $email_key && ! empty( $data[ $email_key ] ) && is_email( $data[ $email_key ] ) ) {
            $responder_subject = str_replace(array_keys($placeholders), array_values($placeholders), $settings['responder_subject']);
            $responder_body = wpautop(str_replace(array_keys($placeholders), array_values($placeholders), $settings['responder_body']));
            wp_mail( $data[$email_key], $responder_subject, $responder_body, $headers );
        }
    }

    private function redirect_with_feedback( int $form_id, string $type, string $text ) {
        // Key the transient on form + user/IP to prevent concurrent submissions
        // from overwriting each other's feedback.
        $user_key = is_user_logged_in() ? get_current_user_id() : md5( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0' ) ) );
        $transient_key = 'clisyc_form_fb_' . $form_id . '_' . $user_key;
        set_transient( $transient_key, [ 'type' => $type, 'text' => $text ], 60 );

        $redirect_url = wp_get_referer() ? wp_get_referer() : home_url();
        wp_safe_redirect( $redirect_url . '#clisyc-form-' . $form_id );
        exit;
    }
}