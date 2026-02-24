<?php
/**
 * File: src/shared/includes/admin/views/template-parts/part-schedule-editor.php
 * A reusable template part for the visual Weekly Availability editor.
 * REFACTORED: Now serves as the mount point for the React application.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="clisyc-schedule-editor-root" class="clisyc-service-schedule-metabox-wrapper">
    <p style="text-align:center; padding:50px;"><?php esc_html_e( 'Loading Schedule Editor...', 'client-sync' ); ?></p>
</div>