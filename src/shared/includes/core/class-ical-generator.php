<?php
/**
 * File: src/shared/includes/core/class-ical-generator.php -> client-sync/includes/core/class-ical-generator.php
 * Handles the generation and download requests for iCalendar (.ics) files.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Core
 */

namespace DependentMedia\ClientSync\Core;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ical_Generator {

    public function register_hooks() {
        add_action( 'init', [ $this, 'handle_export_request' ] );
    }

    public function handle_export_request() {
        // *** REFACTORED ***: Updated $_GET parameter key
        if ( ! isset( $_GET['clisyc_action'], $_GET['appointment_id'] ) || 'export_ical' !== $_GET['clisyc_action'] ) {
            return;
        }

        $appointment_id = absint( $_GET['appointment_id'] );
        if ( ! $appointment_id ) {
            wp_die( esc_html__( 'Invalid appointment ID for iCal export.', 'client-sync' ), esc_html__( 'Error', 'client-sync' ), 400 );
        }
        
        // *** REFACTORED ***: Updated nonce action name
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'clisyc_export_ical_' . $appointment_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'client-sync' ), esc_html__( 'Error', 'client-sync' ), 403 );
        }

        $appointment = get_post( $appointment_id );

        // *** REFACTORED ***: Updated CPT slug to 'clisyc_appointment'
        if ( ! $appointment || Constants::POST_TYPE_APPOINTMENT !== $appointment->post_type ) {
            wp_die( esc_html__( 'Appointment not found for iCal export.', 'client-sync' ), esc_html__( 'Error', 'client-sync' ), 404 );
        }
        
        // *** REFACTORED ***: Updated filter name
        if ( ! is_user_logged_in() || ( get_current_user_id() != $appointment->post_author && ! current_user_can( apply_filters('clisyc_manager_view_capability', 'edit_others_posts') ) ) ) {
            wp_die( esc_html__( 'You do not have permission to export this appointment.', 'client-sync' ), esc_html__( 'Error', 'client-sync' ), 403 );
        }
        
        $ical_data = $this->generate_ical_for_appointment( $appointment_id );

        if ( $ical_data ) {
            $filename = 'appointment-' . $appointment_id . '.ics';
            
            header( 'Content-Type: text/calendar; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is intentional. We are outputting a raw .ics file, not HTML. Escaping would corrupt the file.
            echo $ical_data;
            exit;
        } else {
            wp_die( esc_html__( 'Could not generate iCalendar file for this appointment. It may be missing necessary details like date or duration.', 'client-sync' ), esc_html__( 'Error', 'client-sync' ), 500 );
        }
    }

    private function generate_ical_for_appointment( int $appointment_id ) {
        $appointment = get_post( $appointment_id );
        
        // *** REFACTORED ***: Updated meta keys to use new prefix/constants
        $time_slot_utc_str = get_post_meta( $appointment_id, Constants::META_TIME_SLOT, true );
        $duration_minutes  = get_post_meta( $appointment_id, Constants::META_APPOINTMENT_DURATION, true );

        if ( empty( $time_slot_utc_str ) || empty( $duration_minutes ) || ! is_numeric( $duration_minutes ) || $duration_minutes <= 0 ) {
            return false;
        }

        try {
            $start_datetime_utc = new \DateTime( $time_slot_utc_str, new \DateTimeZone('UTC') );
            $end_datetime_utc = ( clone $start_datetime_utc )->modify( "+{$duration_minutes} minutes" );

            $dtstart = $start_datetime_utc->format( 'Ymd\THis\Z' );
            $dtend   = $end_datetime_utc->format( 'Ymd\THis\Z' );
            $dtstamp = gmdate( 'Ymd\THis\Z' );
            $uid     = md5( uniqid( (string) wp_rand(), true ) ) . '@' . wp_parse_url( home_url(), PHP_URL_HOST );

            $summary     = get_the_title( $appointment_id );
            $description = wp_strip_all_tags( $appointment->post_content );
            
            $client_info = get_userdata( $appointment->post_author );
            $client_name = $client_info ? $client_info->display_name : '';
            $client_email = $client_info ? $client_info->user_email : '';

            // *** REFACTORED ***: Updated option names
            $organizer_name = get_option( Constants::OPTION_EMAIL_FROM_NAME, get_bloginfo( 'name' ) );
            $organizer_email = get_option( Constants::OPTION_EMAIL_FROM_ADDRESS, '' ) ?: get_option( 'admin_email' );

            $ical_parts = [
                "BEGIN:VCALENDAR",
                "VERSION:2.0",
                "PRODID:-//ClientSyncPlugin//NONSGML v1.0//EN",
                "CALSCALE:GREGORIAN",
                "BEGIN:VEVENT",
                "UID:{$uid}",
                "DTSTAMP:{$dtstamp}",
                "DTSTART:{$dtstart}",
                "DTEND:{$dtend}",
                "SUMMARY:" . $this->escape_ical_text( $summary ),
                "DESCRIPTION:" . $this->escape_ical_text( $description ),
                "ORGANIZER;CN=\"" . $this->escape_ical_text($organizer_name) . "\":MAILTO:" . $organizer_email,
            ];
            
            if ($client_email) {
                $ical_parts[] = "ATTENDEE;CN=\"" . $this->escape_ical_text($client_name) . "\";ROLE=REQ-PARTICIPANT:mailto:" . $client_email;
            }

            $ical_parts[] = "END:VEVENT";
            $ical_parts[] = "END:VCALENDAR";

            return implode( "\r\n", $ical_parts );

        } catch ( \Exception $e ) {
            return false;
        }
    }

    private function escape_ical_text( $text ) {
        $text = str_replace( "\\", "\\\\", $text );
        $text = str_replace( ",", "\,", $text );
        $text = str_replace( ";", "\;", $text );
        $text = preg_replace( "/\r\n|\n|\r/", "\\n", $text );
        return $text;
    }
}