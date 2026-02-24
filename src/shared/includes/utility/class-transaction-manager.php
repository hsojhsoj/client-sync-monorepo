<?php
/**
 * File: src/shared/includes/utility/class-transaction-manager.php
 * Centralized database transaction wrapper for Client Sync.
 *
 * Replaces the repetitive pattern:
 *   $wpdb->query( 'START TRANSACTION' );
 *   try { ... $wpdb->query( 'COMMIT' ); } catch ( \Exception $e ) { $wpdb->query( 'ROLLBACK' ); }
 *
 * Usage:
 *   $result = Transaction_Manager::execute( function () use ( $wpdb, $data ) {
 *       $wpdb->insert( ... );
 *       return $wpdb->insert_id;
 *   } );
 *
 * @package    ClientSync
 * @subpackage ClientSync/Utility
 */

namespace DependentMedia\ClientSync\Utility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Transaction_Manager {

	/**
	 * Execute a callback inside a database transaction.
	 *
	 * If the callback returns normally, the transaction is committed.
	 * If the callback throws an exception, the transaction is rolled back
	 * and the exception is re-thrown.
	 *
	 * @param callable $callback The work to perform inside the transaction.
	 *                           Receives no arguments. Use closures to capture dependencies.
	 * @return mixed Whatever the callback returns.
	 * @throws \Exception Re-thrown from the callback after rollback.
	 */
	public static function execute( callable $callback ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$result = $callback();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'COMMIT' );

			return $result;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'ROLLBACK' );

			Debug_Logger::log( 'Transaction rolled back: ' . $e->getMessage(), 'DB' );

			throw $e;
		}
	}
}
