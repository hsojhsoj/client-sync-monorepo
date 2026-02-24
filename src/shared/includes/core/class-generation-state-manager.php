<?php
/**
 * File: src/shared/includes/core/class-generation-state-manager.php
 * Manages the persistent state of the slot generation process to allow batching.
 */

namespace DependentMedia\ClientSync\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Generation_State_Manager {

    const OPTION_KEY = 'clisyc_slot_gen_state';

    /**
     * Get the current state or initialize a fresh one.
     */
    public function get_state(): array {
        $state = get_option( self::OPTION_KEY, [] );
        
        if ( empty( $state ) || empty( $state['in_progress'] ) ) {
            return [
                'in_progress'       => false,
                'current_post_index'=> 0,
                'current_date_utc'  => null, // 'Y-m-d'
                'stats'             => ['inserted' => 0, 'errors' => 0],
            ];
        }
        
        return $state;
    }

    /**
     * Save the current state to the database.
     */
    public function save_state( int $post_index, string $date_utc, array $stats ) {
        update_option( self::OPTION_KEY, [
            'in_progress'       => true,
            'current_post_index'=> $post_index,
            'current_date_utc'  => $date_utc,
            'stats'             => $stats,
            'last_updated'      => time()
        ] );
    }

    /**
     * Clear the state when generation completes successfully.
     */
    public function clear_state() {
        delete_option( self::OPTION_KEY );
    }
    
    /**
     * Check if a previous run stalled (e.g. PHP crashed).
     * If last update was > 10 minutes ago, we assume it crashed and should reset or retry.
     */
    public function is_stalled(): bool {
        $state = get_option( self::OPTION_KEY, [] );
        if ( empty( $state['last_updated'] ) ) return false;
        
        return ( time() - $state['last_updated'] ) > ( 10 * MINUTE_IN_SECONDS );
    }
}