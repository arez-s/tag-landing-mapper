<?php
/** Render the mapped page while keeping the main WP query on the original tag. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
if ( $is_block_theme ) :
    // Block themes do not usually ship header.php or footer.php.
    ?><!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php wp_head(); ?>
    </head>
    <body <?php body_class(); ?>>
    <?php wp_body_open(); ?>
    <div class="wp-site-blocks">
        <?php echo do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php else :
    get_header();
endif;
?>
<main id="tlm-landing-main" class="tlm-landing-main" role="main">
    <?php
    // The plugin does not alter the archive's term, permalink, or SEO query context.
    if ( isset( $GLOBALS['tlm_plugin_instance'] ) && is_object( $GLOBALS['tlm_plugin_instance'] ) ) {
        $GLOBALS['tlm_plugin_instance']->render_landing();
    }
    ?>
</main>
<?php if ( $is_block_theme ) : ?>
        <?php echo do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
    <?php wp_footer(); ?>
    </body>
    </html>
<?php else :
    get_footer();
endif;
