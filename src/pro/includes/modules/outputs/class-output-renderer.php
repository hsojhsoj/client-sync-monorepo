<?php
/**
 * File: src/pro/includes/modules/outputs/class-output-renderer.php -> client-sync-pro/includes/modules/outputs/class-output-renderer.php
 * Output Renderer: Parses template JSON and renders to HTML/PDF/etc.
 *
 * Fetches appointment data agnostically and replaces placeholders.
 * Supports text, tables; extensible for images/sections.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Outputs
 */

namespace ClientSyncPro\Modules\Outputs;

use Dompdf\Dompdf; // From Composer

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Output_Renderer {
    private $template_json;
    private $data; // Appointment context

    public function __construct( $template_id, $appointment_id ) {
        $this->template_json = get_post_meta( $template_id, 'clisyc_template_json', true );
        $this->data = $this->get_appointment_data( $appointment_id );
    }

    /**
     * Creates a renderer with sample placeholder data for previewing templates.
     *
     * @param int $template_id The output template post ID.
     * @return self
     */
    public static function with_sample_data( int $template_id ): self {
        $instance = new self( 0, 0 ); // Skip real data loading.
        $instance->template_json = get_post_meta( $template_id, 'clisyc_template_json', true );
        $instance->data = [
            'site_name'                   => get_bloginfo( 'name' ),
            'site_url'                    => home_url(),
            'client_id'                   => '42',
            'client_name'                 => 'Jane Smith',
            'client_email'                => 'jane@example.com',
            'client_username'             => 'janesmith',
            'client_first_name'           => 'Jane',
            'client_last_name'            => 'Smith',
            'client_display_name'         => 'Jane Smith',
            'phone_number'                => '(555) 123-4567',
            'appointment_id'              => '1024',
            'appointment_date'            => '2025-03-15',
            'appointment_date_formatted'  => 'March 15, 2025',
            'appointment_time'            => '10:00 AM',
            'appointment_time_slot'       => '10:00',
            'appointment_duration'        => '60',
            'appointment_notes'           => 'First-time consultation.',
            'appointment_edit_link_admin' => admin_url( 'post.php?action=edit&post=1024' ),
            'appointment_view_link'       => home_url( '/my-appointments/?id=1024' ),
            'order_id'                    => '5678',
            'order_total'                 => '$75.00',
            'order_link_admin'            => admin_url( 'post.php?action=edit&post=5678' ),
            'order_payment_method_title'  => 'Visa ending in 4242',
            'failure_reason'              => 'Card declined.',
            'custom_fields'               => [],
        ];
        return $instance;
    }

    /**
     * Fetch agnostic data.
     */
    private function get_appointment_data( $appointment_id ) {
        if ( ! $appointment_id ) {
            return [ 'custom_fields' => [] ];
        }

        $post = get_post( $appointment_id );
        if ( ! $post ) {
            return [ 'custom_fields' => [] ];
        }

        $custom = get_post_meta( $appointment_id, 'clisyc_custom_fields', true ) ?: [];
        $dimensions = clisyc_get_dimension_context( $appointment_id ); // Reuse from graph manager

        return [
            'client_name'    => get_post_meta( $post->post_author, 'clisyc_client_name', true ) ?: get_the_author_meta( 'display_name', $post->post_author ),
            'appointment_date' => $post->post_date,
            'service_type'   => $dimensions['primary'] ?? '',
            'custom_fields'  => $custom,
        ];
    }

    /**
     * Render to HTML (base for all channels).
     */
    public function render_html() {
        $json = json_decode( $this->template_json, true );
        if ( ! is_array( $json ) ) {
            return '<p>Error: Invalid template.</p>';
        }

        $html = '<div class="clisyc-output-document">';
        foreach ( $json['sections'] ?? [] as $section ) { // Assume JSON structure: {sections: [{type: 'text', content: '{client_name}'}, {type: 'table', fields: ['field1', 'field2']}]}
            $html .= $this->render_section( $section );
        }
        $html .= '</div>';

        return $this->replace_placeholders( $html );
    }

    /**
     * Replace {placeholders} with data.
     */
    private function replace_placeholders( $html ) {
        foreach ( $this->data as $key => $value ) {
            if ( is_array( $value ) ) {
                continue;
            }
            $html = str_replace( '{' . $key . '}', esc_html( $value ), $html );
        }
        $custom_fields = $this->data['custom_fields'] ?? [];
        foreach ( $custom_fields as $cf_key => $cf_value ) {
            if ( is_string( $cf_value ) || is_numeric( $cf_value ) ) {
                $html = str_replace( '{' . $cf_key . '}', esc_html( $cf_value ), $html );
            }
        }
        return $html;
    }

    /**
     * Render section (e.g., text, table).
     */
    private function render_section( $section ) {
        switch ( $section['type'] ) {
            case 'text':
                return '<div class="clisyc-output-text">' . wp_kses_post( $section['content'] ) . '</div>';
            case 'table':
                $rows = '<table><tr>';
                foreach ( $section['fields'] as $field ) {
                    $rows .= '<th>' . esc_html( $field ) . '</th>';
                }
                $rows .= '</tr><tr>';
                // Add data rows here (simplified for MVP)
                $rows .= '<td colspan="' . count( $section['fields'] ) . '">Sample data row</td></tr></table>';
                return $rows;
            default:
                return '';
        }
    }

    /**
     * Generate PDF.
     */
    public function render_pdf() {
        if ( ! class_exists( 'Dompdf\Dompdf' ) ) {
            return false;
        }

        $html = $this->render_html();
        $dompdf = new Dompdf();
        $dompdf->loadHtml( $html );
        $dompdf->setPaper( 'A4', 'portrait' );
        $dompdf->render();
        return $dompdf->output();
    }

    /**
     * SMS summary (truncated).
     */
    public function render_sms() {
        // Replace strip_tags() with wp_strip_all_tags()
        $html = wp_strip_all_tags( $this->render_html() );
        
        return substr( $html, 0, 160 ) . '...';
    }
}