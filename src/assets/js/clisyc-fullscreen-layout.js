/**
 * File: src/assets/js/clisyc-fullscreen-layout.js
 * Handles the mobile sidebar toggle and help panel for the full-screen admin layout.
 *
 * @package ClientSync
 */

( function( $ ) {
	'use strict';

	$( document ).ready( function() {
		var $sidebar  = $( '#clisyc-admin-sidebar' );
		var $toggle   = $( '#clisyc-sidebar-toggle' );
		var $body     = $( 'body' );

		// Create overlay element for mobile.
		var $overlay = $( '<div id="clisyc-sidebar-overlay"></div>' );
		$body.append( $overlay );

		// Toggle sidebar on hamburger click.
		$toggle.on( 'click', function( e ) {
			e.preventDefault();
			$sidebar.toggleClass( 'clisyc-sidebar-open' );
			$overlay.toggleClass( 'clisyc-overlay-visible' );
		} );

		// Close sidebar when clicking overlay.
		$overlay.on( 'click', function() {
			$sidebar.removeClass( 'clisyc-sidebar-open' );
			$overlay.removeClass( 'clisyc-overlay-visible' );
		} );

		// ── Help Panel ──────────────────────────────────────
		var $helpToggle = $( '#clisyc-help-toggle' );
		var $helpPanel  = $( '#clisyc-help-panel' );

		if ( $helpToggle.length && $helpPanel.length ) {

			// Toggle help panel.
			$helpToggle.on( 'click', function( e ) {
				e.preventDefault();
				var isOpen = $helpPanel.hasClass( 'clisyc-help-panel-open' );

				if ( isOpen ) {
					$helpPanel.removeClass( 'clisyc-help-panel-open' );
					$helpPanel.attr( 'aria-hidden', 'true' );
					$helpToggle.attr( 'aria-expanded', 'false' );
				} else {
					$helpPanel.addClass( 'clisyc-help-panel-open' );
					$helpPanel.attr( 'aria-hidden', 'false' );
					$helpToggle.attr( 'aria-expanded', 'true' );
				}
			} );

			// Tab switching within help panel.
			$helpPanel.on( 'click', '.clisyc-help-tab-btn', function() {
				var $btn   = $( this );
				var tabId  = $btn.data( 'tab' );

				// Update tab buttons.
				$helpPanel.find( '.clisyc-help-tab-btn' )
					.removeClass( 'clisyc-help-tab-active' )
					.attr( 'aria-selected', 'false' );
				$btn.addClass( 'clisyc-help-tab-active' )
					.attr( 'aria-selected', 'true' );

				// Update tab panels.
				$helpPanel.find( '.clisyc-help-tab-panel' )
					.removeClass( 'clisyc-help-tab-panel-active' );
				$( '#clisyc-help-tab-' + tabId )
					.addClass( 'clisyc-help-tab-panel-active' );
			} );
		}

		// Close sidebar and help panel on Escape key.
		$( document ).on( 'keydown', function( e ) {
			if ( e.key === 'Escape' ) {
				if ( $sidebar.hasClass( 'clisyc-sidebar-open' ) ) {
					$sidebar.removeClass( 'clisyc-sidebar-open' );
					$overlay.removeClass( 'clisyc-overlay-visible' );
				}
				if ( $helpPanel.length && $helpPanel.hasClass( 'clisyc-help-panel-open' ) ) {
					$helpPanel.removeClass( 'clisyc-help-panel-open' );
					$helpPanel.attr( 'aria-hidden', 'true' );
					$helpToggle.attr( 'aria-expanded', 'false' );
				}
			}
		} );
	} );

} )( jQuery );
