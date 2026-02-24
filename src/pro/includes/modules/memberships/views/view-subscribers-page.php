<?php
/**
 * File: src/pro/includes/modules/memberships/views/view-subscribers-page.php
 *      -> client-sync-pro/includes/modules/memberships/views/view-subscribers-page.php
 * View for the Subscribers admin list page.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clisyc_list_table = new \ClientSyncPro\Modules\Memberships\Subscribers_List_Table();
$clisyc_list_table->prepare_items();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Subscribers', 'client-sync-pro' ); ?></h1>

	<form method="get">
		<input type="hidden" name="page" value="clisyc-subscribers" />
		<?php
		$clisyc_list_table->search_box( __( 'Search Subscribers', 'client-sync-pro' ), 'clisyc-subscribers-search' );
		$clisyc_list_table->display();
		?>
	</form>
</div>
