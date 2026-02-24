<?php
/**
 * File: src/shared/includes/admin/views/view-getting-started-page.php -> client-sync/includes/admin/views/view-getting-started-page.php
 * View for the dedicated "Getting Started" admin page.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Variables passed to this view from the render_getting_started_page method:
 * @var array $setup_milestones Data about setup progress, including completion status and URLs.
 */

// Calculate progress percentage for the setup checklist
$clisyc_completed_count = 0;
$clisyc_total_milestones = 0;
$clisyc_first_incomplete_milestone_key = null;

if ( is_array( $setup_milestones ) ) {
    foreach ( $setup_milestones as $clisyc_key => $clisyc_milestone ) {
        if ( ! ( $clisyc_milestone['is_optional'] ?? false ) ) {
            $clisyc_total_milestones++;
            if ( $clisyc_milestone['is_complete'] ) {
                $clisyc_completed_count++;
            } elseif ( ! $clisyc_first_incomplete_milestone_key ) {
                $clisyc_first_incomplete_milestone_key = $clisyc_key;
            }
        }
    }
}
$clisyc_progress_percentage = ( $clisyc_total_milestones > 0 ) ? round( ( $clisyc_completed_count / $clisyc_total_milestones ) * 100 ) : 100;
if ( $clisyc_progress_percentage > 100 ) $clisyc_progress_percentage = 100;

?>
<div class="wrap clisyc-getting-started-page">
    <h1><?php esc_html_e( 'Getting Started with Client Sync', 'client-sync' ); ?></h1>
    <p class="about-text"><?php esc_html_e( 'Follow these steps to configure the essential features of the plugin. Complete the required steps to get your booking system online.', 'client-sync' ); ?></p>

    <div id="clisyc-setup-progress-section" class="postbox">
        <div class="postbox-header">
            <h2 class="hndle"><?php esc_html_e( 'Setup Checklist', 'client-sync' ); ?></h2>
        </div>
        <div class="inside">
            <p><?php
                /*
                * translators: %s: The completion percentage number, which will be wrapped in a <strong> tag.
                */
                $clisyc_progress_text = __( 'You are <strong>%s%%</strong> of the way through the basic setup!', 'client-sync' );
                printf(
                    wp_kses(
                        $clisyc_progress_text,
                        [
                            'strong' => [], // Allow the <strong> tag
                        ]
                    ),
                    esc_html( $clisyc_progress_percentage )
                );
            ?></p>
            <div class="client-sync-progress-bar-container">
                <div class="client-sync-progress-bar" style="width: <?php echo esc_attr( $clisyc_progress_percentage ); ?>%;">
                    <?php echo esc_html( $clisyc_progress_percentage ); ?>%
                </div>
            </div>
            
            <ul class="clisyc-milestone-list">
                <?php foreach ( $setup_milestones as $clisyc_key => $clisyc_milestone ) : ?>
                    <li class="clisyc-milestone-item <?php echo $clisyc_milestone['is_complete'] ? 'completed' : 'pending'; ?>">
                        <span class="dashicons <?php echo $clisyc_milestone['is_complete'] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
                        <strong><?php echo esc_html( $clisyc_milestone['label'] ); ?></strong>
                        <?php if ( $clisyc_milestone['is_optional'] ?? false ) echo ' <em>(' . esc_html__( 'Optional', 'client-sync' ) . ')</em>'; ?>
                        
                        <p class="description"><?php echo wp_kses_post( $clisyc_milestone['description'] ); // Use wp_kses_post to allow for links in description ?></p>
                        
                        <div class="clisyc-milestone-actions">
                            <?php if ( ! $clisyc_milestone['is_complete'] ) : ?>
                                <a href="<?php echo esc_url( $clisyc_milestone['next_step_url'] ); ?>" class="button button-small <?php echo ( $clisyc_key === $clisyc_first_incomplete_milestone_key ) ? 'button-primary' : 'button-secondary'; ?>">
                                    <?php echo esc_html( $clisyc_milestone['next_step_label'] ); ?>
                                </a>
                                <?php if ( $clisyc_milestone['is_manual_completion'] ?? false ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-left: 5px;">
                                        <input type="hidden" name="action" value="clisyc_mark_milestone_complete">
                                        <input type="hidden" name="milestone_key" value="<?php echo esc_attr( $clisyc_key ); ?>">
                                        <?php wp_nonce_field( 'clisyc_mark_milestone_' . $clisyc_key ); ?>
                                        <button type="submit" class="button button-small">
                                            <?php esc_html_e( 'Mark as Done', 'client-sync' ); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ( ! empty( $clisyc_milestone['settings_url'] ) ) : ?>
                                 <a href="<?php echo esc_url( $clisyc_milestone['settings_url'] ); ?>" class="button button-small button-secondary">
                                    <?php esc_html_e( 'View/Edit Settings', 'client-sync' ); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>