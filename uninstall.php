<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
$mappings = get_option( 'tlm_tag_landing_mappings', array() );
if ( is_array( $mappings ) ) {
    foreach ( $mappings as $mapping ) {
        if ( isset( $mapping['taxonomy'], $mapping['term_id'] ) ) {
            foreach ( array( 'starts_at', 'ends_at' ) as $field ) {
                wp_clear_scheduled_hook( 'tlm_refresh_archive_cache', array( $mapping['taxonomy'], (int) $mapping['term_id'], $field ) );
            }
        }
    }
}
delete_option( 'tlm_tag_landing_mappings' );
delete_option( 'tlm_tag_landing_history' );
