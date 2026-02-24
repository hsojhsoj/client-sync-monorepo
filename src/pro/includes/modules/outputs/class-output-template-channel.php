<?php
/**
 * File: src/pro/includes/modules/outputs/class-output-template-channel.php -> client-sync-pro/includes/modules/outputs/class-output-template-channel.php
 * Output Template Channel: Sends via email/SMS with rendered content.
 *
 * Implements Notification_Channel_Interface; handles multi-channel dispatch.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Outputs
 */

 namespace ClientSyncPro\Modules\Outputs;
 
 use DependentMedia\ClientSync\Core\Interfaces\Notification_Channel_Interface;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Output_Template_Channel implements Notification_Channel_Interface {
    /**
     * {@inheritdoc}
     */
    public function send( string $event_type, array $placeholder_data, array $recipients ): bool {
        $appointment_id = (int) ( $placeholder_data['{appointment_id}'] ?? 0 );
        if ( ! $appointment_id ) {
            return false;
        }

        // Find all templates that are set to trigger on this event
        $template_query = new \WP_Query([
            'post_type' => 'clisyc_output_template',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'clisyc_triggers',
                    'value' => serialize( $event_type ), // Stored as a serialized array
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        if ( ! $template_query->have_posts() ) {
            return false;
        }

        $sent = false;
        foreach ( $template_query->posts as $template_id ) {
            $this->dispatch_template( $appointment_id, $template_id, $recipients );
            $sent = true;
        }
        return $sent;
    }

    private function dispatch_template( int $appointment_id, int $template_id, array $recipients ) {
        $renderer = new Output_Renderer( $template_id, $appointment_id );
        $channels = get_post_meta( $template_id, 'clisyc_channels', true ) ?: [];

        $client_recipient = null;
        foreach ( $recipients as $recipient ) {
            if ( 'client' === $recipient['type'] ) {
                $client_recipient = $recipient;
                break;
            }
        }
        if ( ! $client_recipient ) return;
        
        $client_email = $client_recipient['email'];
        $appointment = get_post( $appointment_id );
        $client_phone = get_user_meta( $appointment->post_author, 'phone_number', true );

        if ( in_array( 'email', $channels ) && is_email( $client_email ) ) {
            $subject = get_the_title( $template_id );
            $message = $renderer->render_html();
            $attachments = in_array( 'pdf', $channels ) ? [ $this->generate_pdf_attachment( $renderer, $appointment_id ) ] : [];
            wp_mail( $client_email, $subject, $message, ['Content-Type: text/html; charset=UTF-8'], $attachments );
        }
    }

    private function generate_pdf_attachment( Output_Renderer $renderer, int $appointment_id ): string {
        $pdf_content = $renderer->render_pdf();
        $upload_dir  = wp_upload_dir();
        $output_dir  = $upload_dir['basedir'] . '/clisyc-outputs';

        // Ensure the output directory exists.
        if ( ! is_dir( $output_dir ) ) {
            wp_mkdir_p( $output_dir );
            // Prevent directory listing.
            if ( ! file_exists( $output_dir . '/index.php' ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                @file_put_contents( $output_dir . '/index.php', '<?php // Silence is golden.' );
            }
        }

        $file_path = $output_dir . '/output-' . $appointment_id . '-' . wp_rand() . '.pdf';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = @file_put_contents( $file_path, $pdf_content );
        if ( false === $written ) {
            Debug_Logger::log( 'Failed to write PDF attachment to: ' . $file_path, 'OutputTemplate' );
        }

        return $file_path;
    }

    // Interface stubs
    public function get_id(): string { return 'output_template'; }
    public function get_label(): string { return __( 'Output Template', 'client-sync-pro' ); }
}