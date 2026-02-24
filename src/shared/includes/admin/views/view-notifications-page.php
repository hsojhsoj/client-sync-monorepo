<?php
/**
 * File: src/shared/includes/admin/views/view-notifications-page.php -> client-sync/includes/admin/views/view-notifications-page.php
 * View for the Notifications Settings admin page.
 *
 * This file renders the settings page where administrators can configure
 * email and SMS templates, and general notification settings.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Notification Settings', 'client-sync' ); ?></h1>

	<p>
		<?php esc_html_e( 'Customize the messages sent to clients and administrators for various events. Use the placeholders provided in each section to include dynamic information.', 'client-sync' ); ?>
	</p>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'clisyc_notification_settings_group' );
		do_settings_sections( 'clisyc-notifications-settings' );
		submit_button();
		?>
	</form>
</div>