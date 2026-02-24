<?php
/**
 * File: src/shared/includes/admin/views/view-integrations-page.php -> client-sync/includes/admin/views/view-integrations-page.php
 * View for the Integrations Settings admin page.
 *
 * This file renders the settings page where administrators can configure
 * connections to third-party services like Google Calendar, Twilio, and Webhooks.
 * MODIFIED: Added a notice guiding users to set a Personnel Dimension for Google Sync.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DependentMedia\ClientSync\Constants;

$clisyc_webhooks = get_option( Constants::OPTION_WEBHOOKS, array() );
if ( ! is_array( $clisyc_webhooks ) ) {
	$clisyc_webhooks = array();
}
$clisyc_all_notification_events = ( new \DependentMedia\ClientSync\Core\Notifications() )->get_event_types();
$clisyc_is_pro_active           = function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active();
?>
<div class="wrap clisyc-integrations-settings">
	<form method="post" action="options.php">
		<?php
		settings_fields( 'clisyc_integrations_settings_group' );
		do_settings_sections( 'clisyc-integrations' ); // Renders Google & reCAPTCHA
		do_settings_sections( 'clisyc-sms-integrations' ); // Renders Twilio
		?>

        <?php // START: NEW INSTRUCTIONAL BLOCK ?>
        <?php if ( $clisyc_is_pro_active ) : ?>
            <?php
            $clisyc_personnel_dim_slug = get_option( Constants::OPTION_PERSONNEL_DIM_SLUG );
            if ( empty( $clisyc_personnel_dim_slug ) ) :
            ?>
            <div class="notice notice-warning notice-alt inline">
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Action Required for Google Calendar Sync', 'client-sync' ); ?></h3>
                <p><?php
                    $clisyc_setup_url = esc_url( admin_url( 'admin.php?page=clisyc-dimensions&tab=setup' ) );
                    /* translators: %s: A link to the System Setup page. */
                    $clisyc_message = __( 'To enable Google Calendar Sync for your staff, you must first designate which of your dimensions represents personnel on the %s.', 'client-sync' );

                    // FIXED: Construct the link safely
                    $clisyc_link_html = sprintf(
                        '<a href="%s">%s</a>',
                        $clisyc_setup_url,
                        esc_html__( 'System Setup page', 'client-sync' )
                    );

                    // Output with allowed tags
                    echo wp_kses(
                        sprintf( $clisyc_message, $clisyc_link_html ),
                        [ 'a' => [ 'href' => [] ] ]
                    );
                ?></p>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php // END: NEW INSTRUCTIONAL BLOCK ?>

		<div class="postbox <?php if ( ! $clisyc_is_pro_active ) echo 'client-sync-pro-feature-locked'; ?>">
			<h2 class="hndle"><span><?php esc_html_e( 'Webhooks (for Zapier, etc.)', 'client-sync' ); ?></span></h2>
			<div class="inside">
				<?php if ( $clisyc_is_pro_active ) : ?>
					<?php
					// This is not a real settings section, just a helper method from the Settings_Manager
					// to display the header text consistently.
					( new \DependentMedia\ClientSync\Admin\Settings_Manager() )->render_webhooks_section_header();
					?>
					<hr>

					<div id="clisyc-webhook-repeater">
						<?php foreach ( $clisyc_webhooks as $clisyc_index => $clisyc_webhook ) : ?>
							<div class="clisyc-webhook-row">
								<h4 class="clisyc-webhook-header">
									<span><?php esc_html_e( 'Webhook Endpoint', 'client-sync' ); ?></span>
									<button type="button" class="button-link clisyc-remove-webhook-btn"><span class="dashicons dashicons-trash"></span></button>
								</h4>
								<div class="clisyc-webhook-body">
									<p><label><input type="checkbox" name="clisyc_webhooks[<?php echo esc_attr( $clisyc_index ); ?>][enabled]" value="1" <?php checked( ! empty( $clisyc_webhook['enabled'] ) ); ?>> <strong><?php esc_html_e( 'Enabled', 'client-sync' ); ?></strong></label></p>
									<p><label><strong><?php esc_html_e( 'URL', 'client-sync' ); ?></strong><input type="url" name="clisyc_webhooks[<?php echo esc_attr( $clisyc_index ); ?>][url]" value="<?php echo esc_attr( $clisyc_webhook['url'] ?? '' ); ?>" class="widefat" placeholder="https://hooks.zapier.com/..."></label></p>
									<fieldset>
										<legend><strong><?php esc_html_e( 'Trigger Events', 'client-sync' ); ?></strong></legend>
										<?php foreach ( $clisyc_all_notification_events as $clisyc_event_key => $clisyc_event_data ) : ?>
											<label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;"><input type="checkbox" name="clisyc_webhooks[<?php echo esc_attr( $clisyc_index ); ?>][events][]" value="<?php echo esc_attr( $clisyc_event_key ); ?>" <?php checked( in_array( $clisyc_event_key, $clisyc_webhook['events'] ?? array() ) ); ?>> <?php echo esc_html( $clisyc_event_data['label'] ); ?></label>
										<?php endforeach; ?>
									</fieldset>
									<p style="margin-top: 1em;"><button type="button" class="button button-secondary clisyc-test-webhook-btn"><?php esc_html_e( 'Send Test', 'client-sync' ); ?></button> <span class="clisyc-webhook-test-result"></span></p>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<p style="margin-top: 15px;"><button type="button" class="button button-secondary" id="clisyc-add-webhook-btn"><?php esc_html_e( 'Add Webhook', 'client-sync' ); ?></button></p>
				<?php else : ?>
					<p><?php echo wp_kses_post( __( 'Webhooks are a Pro feature. Please <a href="?page=client-sync-pro-license">activate your license</a> to enable this section.', 'client-sync' ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<?php submit_button( __( 'Save Integration Settings', 'client-sync' ) ); ?>
	</form>
</div>

<script type="text/html" id="clisyc-webhook-template">
	<div class="clisyc-webhook-row">
		<h4 class="clisyc-webhook-header">
			<span><?php esc_html_e( 'Webhook Endpoint', 'client-sync' ); ?></span>
			<button type="button" class="button-link clisyc-remove-webhook-btn"><span class="dashicons dashicons-trash"></span></button>
		</h4>
		<div class="clisyc-webhook-body">
			<label><strong><?php esc_html_e( 'Enable', 'client-sync' ); ?></strong> <input type="checkbox" name="clisyc_webhooks[__INDEX__][enabled]" value="1" checked></label>
			<label><strong><?php esc_html_e( 'URL', 'client-sync' ); ?></strong><input type="url" name="clisyc_webhooks[__INDEX__][url]" class="widefat" placeholder="https://hooks.zapier.com/..."></label>
			<fieldset>
				<legend><strong><?php esc_html_e( 'Trigger Events', 'client-sync' ); ?></strong></legend>
				<?php foreach ( $clisyc_all_notification_events as $clisyc_event_key => $clisyc_event_data ) : ?>
					<label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;"><input type="checkbox" name="clisyc_webhooks[__INDEX__][events][]" value="<?php echo esc_attr( $clisyc_event_key ); ?>"><?php echo esc_html( $clisyc_event_data['label'] ); ?></label>
				<?php endforeach; ?>
			</fieldset>
			<p style="margin-top: 1em;"><button type="button" class="button button-secondary clisyc-test-webhook-btn"><?php esc_html_e( 'Send Test', 'client-sync' ); ?></button> <span class="clisyc-webhook-test-result"></span></p>
		</div>
	</div>
</script>
<style>.clisyc-webhook-row{border:1px solid #ccd0d4;margin-bottom:15px}.clisyc-webhook-header{margin:0;padding:8px 12px;background:#f6f7f7;border-bottom:1px solid #ccd0d4;display:flex;justify-content:space-between;align-items:center}.clisyc-remove-webhook-btn{color:#a00}.clisyc-remove-webhook-btn:hover{color:#d63638}.clisyc-webhook-body{padding:12px}.clisyc-webhook-body fieldset{margin-top:1em;border-top:1px solid #eee;padding-top:1em}.clisyc-webhook-test-result{font-size:13px;margin-left:6px}</style>
<?php wp_nonce_field( 'clisyc_test_webhook', 'clisyc_test_webhook_nonce' ); ?>
<script>
	jQuery(document).ready(function($){
		var e=<?php echo count( $clisyc_webhooks ); ?>;
		$("#clisyc-add-webhook-btn").on("click",function(){
			var c=$("#clisyc-webhook-template").html().replace(/__INDEX__/g,e);
			$("#clisyc-webhook-repeater").append(c);
			e++;
		});
		$("#clisyc-webhook-repeater").on("click",".clisyc-remove-webhook-btn",function(){
			if(confirm("<?php esc_attr_e( 'Are you sure you want to remove this webhook?', 'client-sync' ); ?>")){
				$(this).closest(".clisyc-webhook-row").remove();
			}
		});
		$("#clisyc-webhook-repeater").on("click",".clisyc-test-webhook-btn",function(){
			var btn=$(this),row=btn.closest(".clisyc-webhook-row"),
				url=row.find("input[type='url']").val(),
				result=row.find(".clisyc-webhook-test-result");
			if(!url){result.css("color","#d63638").text("<?php esc_attr_e( 'Enter a URL first.', 'client-sync' ); ?>");return;}
			btn.prop("disabled",true).text("<?php esc_attr_e( 'Sending…', 'client-sync' ); ?>");
			result.text("");
			$.post(ajaxurl,{
				action:"clisyc_test_webhook",
				nonce:$("#clisyc_test_webhook_nonce").val(),
				url:url
			},function(resp){
				btn.prop("disabled",false).text("<?php esc_attr_e( 'Send Test', 'client-sync' ); ?>");
				if(resp.success){
					result.css("color","#00a32a").text(resp.data.message);
				}else{
					result.css("color","#d63638").text(resp.data.message||"<?php esc_attr_e( 'Test failed.', 'client-sync' ); ?>");
				}
			}).fail(function(){
				btn.prop("disabled",false).text("<?php esc_attr_e( 'Send Test', 'client-sync' ); ?>");
				result.css("color","#d63638").text("<?php esc_attr_e( 'Request failed.', 'client-sync' ); ?>");
			});
		});
	});
</script>