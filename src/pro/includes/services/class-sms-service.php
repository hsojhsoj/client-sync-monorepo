<?php
/**
 * File: src/pro/includes/services/class-sms-service.php
 * A service class for handling interactions with the Twilio API SDK.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Services
 */

namespace ClientSyncPro\Services;

use DependentMedia\ClientSync\Utility\Debug_Logger;
use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sms_Service {

    /**
     * Sends an SMS message using the Twilio API.
     *
     * @param string $to         The recipient's phone number in E.164 format.
     * @param string $message    The body of the SMS message.
     * @param string $event_type Optional. The event type triggering the SMS, for logging purposes.
     * @return bool True on success, false on failure.
     */
    public static function send_sms( string $to, string $message, string $event_type = '' ): bool {
        $creds = get_option( 'clisyc_sms_credentials', [] );

        if ( empty( $creds['twilio_sid'] ) || empty( $creds['twilio_token'] ) || empty( $creds['twilio_from'] ) ) {
            Debug_Logger::log( 'Twilio credentials missing for SMS event: ' . $event_type, 'SMS' );
            return false;
        }

        try {
            $client = new Client( $creds['twilio_sid'], $creds['twilio_token'] );

            $client->messages->create(
                $to,
                [
                    'from' => $creds['twilio_from'],
                    'body' => substr( $message, 0, 1600 ), // Enforce Twilio's max character limit.
                ]
            );

            return true;

        } catch ( TwilioException $e ) {
            Debug_Logger::log( 'SMS Error for event (' . $event_type . ') to ' . $to . ': ' . $e->getMessage(), 'SMS' );
            return false;
        }
    }
}