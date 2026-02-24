<?php
/**
 * File: src/pro/includes/Module_Interface.php
 * Defines the contract for all Pro modules.
 *
 * @package ClientSyncPro
 */

namespace ClientSyncPro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface for all Pro modules.
 * Ensures that any module can be discovered and initialized by the Module_Manager.
 */
interface Module_Interface {

    /**
     * Initializes the module by registering all its necessary hooks and actions.
     *
     * This method replaces the typical 'register_hooks' and acts as the
     * entry point for the module's functionality.
     */
    public function initialize();

}