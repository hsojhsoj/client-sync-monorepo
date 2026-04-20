<?php
/**
 * File: src/shared/includes/frontend/views/view-archive-primary-dimension.php
 * The plugin's universal template for displaying the Primary Dimension archive (e.g., search results).
 * MODIFIED: Improved card structure and styling.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Enqueue the search results styles
wp_enqueue_style(
    'clisyc-search-results',
    clisyc_PLUGIN_URL . 'assets/css/clisyc-search-results.css',
    [ 'dashicons' ],
    defined( 'clisyc_VERSION' ) ? clisyc_VERSION : '1.0.0'
);

// Get filterable fields for displaying attributes
$clisyc_filterable_fields = get_option( \DependentMedia\ClientSync\Constants::OPTION_DIMENSION_FIELDS, [] );

// Get the post type object for the title
$clisyc_post_type_obj = get_queried_object();
$clisyc_archive_title = '';
if ( $clisyc_post_type_obj && is_a( $clisyc_post_type_obj, 'WP_Post_Type' ) && isset( $clisyc_post_type_obj->labels->name ) ) {
    $clisyc_archive_title = $clisyc_post_type_obj->labels->name;
}

// When this file runs as a full page template (via the `template_include`
// filter in class-frontend.php for the primary-dimension post-type archive),
// we need to wrap the output in the theme's header/footer so the page
// inherits site chrome (nav, styles, footer). Without this, the archive
// renders as a bare HTML fragment — no <html>, no <head>, no theme menu.
//
// When the same template is `include`'d by class-search-results-shortcode.php
// on a regular page, the page already has a theme header, so we must NOT
// emit a second one. `is_post_type_archive()` distinguishes the two
// contexts cleanly — it's only true for the standalone archive path.
$clisyc_archive_is_standalone = is_post_type_archive();
if ( $clisyc_archive_is_standalone ) {
    get_header();
}
?>

<header class="page-header clisyc-archive-header">
    <?php
    // Render the search form
    echo do_shortcode( '[clisyc_booking_form mode="search"]' );

    // Display the archive title
    if ( $clisyc_archive_title ) {
        echo '<h1 class="page-title">' . esc_html( $clisyc_archive_title ) . '</h1>';
    }
    ?>
</header>

<div class="clisyc-archive-wrapper">
    <?php if ( have_posts() ) : ?>
        <div class="clisyc-availability-results-list">
            <?php while ( have_posts() ) : the_post();
                $clisyc_item = get_post();
                $clisyc_item_id = $clisyc_item->ID;
                
                // Collect attributes for this item
                $clisyc_attributes = [];
                if ( ! empty( $clisyc_filterable_fields ) ) {
                    foreach ( $clisyc_filterable_fields as $clisyc_key => $clisyc_field ) {
                        // Skip if field doesn't apply to this post type
                        if ( ! in_array( $clisyc_item->post_type, $clisyc_field['applies_to'] ?? [], true ) ) {
                            continue;
                        }
                        
                        $clisyc_meta_value = get_post_meta( $clisyc_item_id, '_clisyc_' . $clisyc_key, true );
                        if ( empty( $clisyc_meta_value ) ) {
                            continue;
                        }
                        
                        $clisyc_attributes[] = [
                            'icon'  => ! empty( $clisyc_field['icon'] ) ? $clisyc_field['icon'] : 'dashicons-admin-generic',
                            'label' => $clisyc_field['label'] ?? ucfirst( $clisyc_key ),
                            'value' => $clisyc_meta_value,
                            'type'  => $clisyc_field['type'] ?? 'text',
                        ];
                    }
                }
                ?>
                <article id="post-<?php echo esc_attr( $clisyc_item_id ); ?>" <?php post_class( 'clisyc-result-card' ); ?>>
                    
                    <?php if ( has_post_thumbnail( $clisyc_item_id ) ) : ?>
                        <div class="clisyc-result-card__image">
                            <?php
                            /* translators: %s: The title of the post/item (e.g. "Beach House"). */
                            $clisyc_view_label = sprintf( __( 'View %s', 'client-sync' ), get_the_title() );
                            ?>
                            <a href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr( $clisyc_view_label ); ?>">
                                <?php the_post_thumbnail( 'medium_large', [ 'loading' => 'lazy' ] ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="clisyc-result-card__content">
                        
                        <header class="clisyc-result-card__header">
                            <h2 class="clisyc-result-card__title">
                                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            </h2>
                        </header>
                        
                        <?php if ( ! empty( $clisyc_attributes ) ) : ?>
                            <ul class="clisyc-result-card__attributes">
                                <?php foreach ( $clisyc_attributes as $clisyc_attr ) : ?>
                                    <li>
                                        <span class="dashicons <?php echo esc_attr( $clisyc_attr['icon'] ); ?>" aria-hidden="true"></span>
                                        <?php if ( 'number' === $clisyc_attr['type'] ) : ?>
                                            <span><?php echo esc_html( $clisyc_attr['value'] ); ?> <?php echo esc_html( $clisyc_attr['label'] ); ?></span>
                                        <?php else : ?>
                                            <span><?php echo esc_html( $clisyc_attr['value'] ); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if ( has_excerpt() || get_the_content() ) : ?>
                            <div class="clisyc-result-card__description">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                        
                        <footer class="clisyc-result-card__actions">
                            <a href="<?php the_permalink(); ?>" class="button button-primary">
                                <?php esc_html_e( 'View & Book', 'client-sync' ); ?>
                                <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true" style="font-size: 16px; width: 16px; height: 16px; line-height: 1;"></span>
                            </a>
                        </footer>
                        
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
        
        <?php 
        // Pagination
        the_posts_pagination( [
            'mid_size'  => 2,
            'prev_text' => '&larr; ' . __( 'Previous', 'client-sync' ),
            'next_text' => __( 'Next', 'client-sync' ) . ' &rarr;',
        ] ); 
        ?>
        
    <?php else : ?>
        <div class="clisyc-no-results clisyc-form-section">
            <p><?php esc_html_e( 'Sorry, no properties match your current criteria. Please try a different search.', 'client-sync' ); ?></p>
        </div>
    <?php endif; ?>

<?php
// Close the theme's body wrapper only if we opened it above.
if ( $clisyc_archive_is_standalone ) {
    get_footer();
}
?>
</div>