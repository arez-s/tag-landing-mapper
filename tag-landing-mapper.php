<?php
/**
 * Plugin Name: Tag Landing Mapper
 * Description: Assign a WordPress page to a post tag or WooCommerce product tag, schedule promotions, view change history, and roll back changes.
 * Version: 1.0.0
 * Author: Custom WordPress Tools
 * Text Domain: tag-landing-mapper
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class TLM_Tag_Landing_Mapper {
    const OPTION_MAPPINGS = 'tlm_tag_landing_mappings';
    const OPTION_HISTORY  = 'tlm_tag_landing_history';
    const MENU_SLUG       = 'tlm-tag-landing';
    const HISTORY_LIMIT   = 300;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'wp_ajax_tlm_find_terms', array( $this, 'ajax_find_terms' ) );
        add_action( 'wp_ajax_tlm_find_pages', array( $this, 'ajax_find_pages' ) );
        add_action( 'admin_post_tlm_save_mapping', array( $this, 'handle_save' ) );
        add_action( 'admin_post_tlm_change_mapping', array( $this, 'handle_change' ) );
        add_action( 'admin_post_tlm_restore_mapping', array( $this, 'handle_restore' ) );
        add_filter( 'template_include', array( $this, 'choose_template' ), 9999 );
        add_filter( 'body_class', array( $this, 'body_class' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ), 25 );
        add_action( 'tlm_refresh_archive_cache', array( $this, 'purge_archive_cache' ), 10, 3 );
    }

    private function taxonomies() {
        $result = array( 'post_tag' => 'برچسب نوشته‌ها' );
        if ( taxonomy_exists( 'product_tag' ) ) {
            $result['product_tag'] = 'برچسب محصولات ووکامرس';
        }
        return $result;
    }

    private function get_mappings() {
        $value = get_option( self::OPTION_MAPPINGS, array() );
        return is_array( $value ) ? $value : array();
    }

    private function get_history() {
        $value = get_option( self::OPTION_HISTORY, array() );
        return is_array( $value ) ? $value : array();
    }

    private function mapping_key( $taxonomy, $term_id ) {
        return $taxonomy . ':' . absint( $term_id );
    }

    private function parse_key( $key ) {
        if ( ! is_string( $key ) || ! preg_match( '/^(post_tag|product_tag):([1-9][0-9]*)$/', $key, $m ) ) {
            return false;
        }
        if ( ! isset( $this->taxonomies()[ $m[1] ] ) ) {
            return false;
        }
        return array( $m[1], (int) $m[2] );
    }

    private function get_term_for_mapping( $mapping ) {
        $term = get_term( (int) $mapping['term_id'], $mapping['taxonomy'] );
        return ( $term instanceof WP_Term && ! is_wp_error( $term ) ) ? $term : null;
    }

    private function term_label( $taxonomy, $term_id ) {
        $term = get_term( (int) $term_id, $taxonomy );
        if ( $term instanceof WP_Term && ! is_wp_error( $term ) ) {
            return $term->name . ' (' . ( isset( $this->taxonomies()[ $taxonomy ] ) ? $this->taxonomies()[ $taxonomy ] : $taxonomy ) . ')';
        }
        return 'برچسب حذف‌شده #' . absint( $term_id );
    }

    private function page_label( $page_id ) {
        $page = get_post( (int) $page_id );
        if ( ! $page || 'page' !== $page->post_type ) {
            return 'برگه حذف‌شده #' . absint( $page_id );
        }
        return get_the_title( $page ) . ' (#' . absint( $page_id ) . ')';
    }

    private function parse_local_date( $raw ) {
        if ( '' === $raw ) {
            return null;
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw ) ) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $raw, wp_timezone() );
        if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $raw ) {
            return false;
        }
        return $date->getTimestamp();
    }

    private function status( $mapping ) {
        if ( empty( $mapping['enabled'] ) ) {
            return 'disabled';
        }
        $now = time();
        $start = $this->parse_local_date( isset( $mapping['starts_at'] ) ? $mapping['starts_at'] : '' );
        $end   = $this->parse_local_date( isset( $mapping['ends_at'] ) ? $mapping['ends_at'] : '' );
        if ( false === $start || false === $end ) {
            return 'invalid';
        }
        if ( null !== $start && $start > $now ) {
            return 'scheduled';
        }
        if ( null !== $end && $end <= $now ) {
            return 'expired';
        }
        return 'active';
    }

    private function status_label( $status ) {
        $labels = array(
            'active'    => 'فعال',
            'disabled'  => 'غیرفعال',
            'scheduled' => 'در انتظار شروع',
            'expired'   => 'پایان‌یافته',
            'invalid'   => 'تاریخ نامعتبر',
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
    }

    private function active_mapping() {
        if ( is_admin() || is_feed() || is_robots() || is_embed() || is_preview() ) {
            return null;
        }
        if ( ! is_tag() && ! is_tax( 'product_tag' ) ) {
            return null;
        }
        $term = get_queried_object();
        if ( ! ( $term instanceof WP_Term ) || ! isset( $this->taxonomies()[ $term->taxonomy ] ) ) {
            return null;
        }
        // The main query stays a tag/taxonomy archive, preserving its URL and SEO context.
        $key      = $this->mapping_key( $term->taxonomy, $term->term_id );
        $mappings = $this->get_mappings();
        if ( ! isset( $mappings[ $key ] ) || 'active' !== $this->status( $mappings[ $key ] ) ) {
            return null;
        }
        $mapping = $mappings[ $key ];
        if ( 'publish' !== get_post_status( (int) $mapping['page_id'] ) || 'page' !== get_post_type( (int) $mapping['page_id'] ) ) {
            return null;
        }
        return $mapping;
    }

    public function choose_template( $template ) {
        if ( $this->active_mapping() ) {
            return plugin_dir_path( __FILE__ ) . 'templates/landing.php';
        }
        return $template;
    }

    public function body_class( $classes ) {
        if ( $this->active_mapping() ) {
            $classes[] = 'tlm-landing-active';
        }
        return $classes;
    }

    public function frontend_assets() {
        $mapping = $this->active_mapping();
        if ( ! $mapping ) {
            return;
        }
        wp_enqueue_style( 'tlm-landing', plugins_url( 'assets/frontend.css', __FILE__ ), array(), '1.0.0' );
        $page_id = (int) $mapping['page_id'];
        if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
            $elementor = \Elementor\Plugin::$instance;
            if ( $elementor && method_exists( $elementor->frontend, 'enqueue_styles' ) ) {
                $elementor->frontend->enqueue_styles();
            }
            if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) && method_exists( '\\Elementor\\Core\\Files\\CSS\\Post', 'create' ) ) {
                \Elementor\Core\Files\CSS\Post::create( $page_id )->enqueue();
            }
        }
    }

    public function render_landing() {
        $mapping = $this->active_mapping();
        if ( ! $mapping ) {
            return;
        }
        $page = get_post( (int) $mapping['page_id'] );
        if ( ! $page ) {
            return;
        }
        global $post;
        $previous_post = $post;
        $post = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata( $post );
        try {
            if ( post_password_required( $page ) ) {
                echo get_the_password_form( $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } elseif ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) &&
                \Elementor\Plugin::$instance->db->is_built_with_elementor( $page->ID ) ) {
                echo \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $page->ID, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                echo apply_filters( 'the_content', $page->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        } finally {
            $post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            if ( $previous_post instanceof WP_Post ) {
                setup_postdata( $previous_post );
            } else {
                wp_reset_postdata();
            }
        }
    }

    public function register_menu() {
        add_management_page( 'مدیریت لندینگ تگ‌ها', 'لندینگ تگ‌ها', 'manage_options', self::MENU_SLUG, array( $this, 'admin_page' ) );
    }

    public function admin_assets( $hook ) {
        if ( 'tools_page_' . self::MENU_SLUG !== $hook ) {
            return;
        }
        wp_enqueue_style( 'tlm-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), '1.0.0' );
        wp_enqueue_script( 'tlm-admin', plugins_url( 'assets/admin.js', __FILE__ ), array( 'jquery', 'jquery-ui-autocomplete' ), '1.0.0', true );
        wp_localize_script( 'tlm-admin', 'tlmAdmin', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'tlm_admin_search' ),
            'choose'   => 'یکی از نتایج را انتخاب کنید.',
            'confirm'  => 'از انجام این تغییر مطمئن هستید؟',
            'noResults'=> 'نتیجه‌ای پیدا نشد.',
        ) );
    }

    public function ajax_find_terms() {
        $this->authorize_ajax();
        $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
        $search   = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
        if ( ! isset( $this->taxonomies()[ $taxonomy ] ) || '' === trim( $search ) ) {
            wp_send_json( array() );
        }
        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 20,
            'search'     => $search,
            'orderby'    => 'name',
        ) );
        if ( is_wp_error( $terms ) ) {
            wp_send_json( array() );
        }
        $results = array();
        foreach ( $terms as $term ) {
            $results[] = array( 'label' => $term->name . ' (' . $term->slug . ', #' . $term->term_id . ')', 'value' => $term->name, 'id' => $term->term_id );
        }
        wp_send_json( $results );
    }

    public function ajax_find_pages() {
        $this->authorize_ajax();
        $search = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
        if ( '' === trim( $search ) ) {
            wp_send_json( array() );
        }
        $query = new WP_Query( array(
            'post_type'              => 'page',
            'post_status'            => 'publish',
            'posts_per_page'         => 20,
            's'                      => $search,
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'suppress_filters'       => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );
        $results = array();
        foreach ( $query->posts as $page ) {
            $results[] = array( 'label' => get_the_title( $page ) . ' (#' . $page->ID . ')', 'value' => get_the_title( $page ), 'id' => $page->ID );
        }
        wp_send_json( $results );
    }

    private function authorize_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }
        check_ajax_referer( 'tlm_admin_search', 'nonce' );
    }

    private function authorize_post( $nonce_action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'شما مجوز مدیریت این افزونه را ندارید.' );
        }
        check_admin_referer( $nonce_action );
    }

    private function go_back( $notice, $edit = '' ) {
        $args = array( 'page' => self::MENU_SLUG, 'tlm_notice' => $notice );
        if ( '' !== $edit ) {
            $args['edit'] = $edit;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
        exit;
    }

    private function record_history( $action, $key, $before, $after, $source_id = '' ) {
        $history = $this->get_history();
        array_unshift( $history, array(
            'id'         => wp_generate_uuid4(),
            'at'         => current_datetime()->format( 'Y-m-d H:i:s' ),
            'action'     => $action,
            'key'        => $key,
            'before'     => $before,
            'after'      => $after,
            'user_id'    => get_current_user_id(),
            'source_id'  => $source_id,
        ) );
        update_option( self::OPTION_HISTORY, array_slice( $history, 0, self::HISTORY_LIMIT ), false );
    }

    private function store_change( $key, $before, $after, $action, $source_id = '' ) {
        $mappings = $this->get_mappings();
        if ( null === $after ) {
            unset( $mappings[ $key ] );
        } else {
            $mappings[ $key ] = $after;
        }
        update_option( self::OPTION_MAPPINGS, $mappings, false );
        $this->record_history( $action, $key, $before, $after, $source_id );
        $parts = $this->parse_key( $key );
        if ( $parts ) {
            $this->schedule_cache_refreshes( $parts[0], $parts[1], $after );
            $this->purge_archive_cache( $parts[0], $parts[1] );
        }
    }

    private function schedule_cache_refreshes( $taxonomy, $term_id, $mapping ) {
        foreach ( array( 'starts_at', 'ends_at' ) as $field ) {
            wp_clear_scheduled_hook( 'tlm_refresh_archive_cache', array( $taxonomy, (int) $term_id, $field ) );
        }
        if ( ! $mapping || empty( $mapping['enabled'] ) ) {
            return;
        }
        foreach ( array( 'starts_at', 'ends_at' ) as $field ) {
            $date = $this->parse_local_date( isset( $mapping[ $field ] ) ? $mapping[ $field ] : '' );
            if ( is_int( $date ) && $date > time() ) {
                wp_schedule_single_event( $date + 1, 'tlm_refresh_archive_cache', array( $taxonomy, (int) $term_id, $field ) );
            }
        }
    }

    public function purge_archive_cache( $taxonomy, $term_id, $event = '' ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return;
        }
        $url = get_term_link( (int) $term_id, $taxonomy );
        if ( is_wp_error( $url ) || ! $url ) {
            return;
        }
        // Best-effort integration; unknown caches/CDNs may still need manual purging.
        do_action( 'litespeed_purge_url', $url );
        if ( function_exists( 'rocket_clean_files' ) ) {
            rocket_clean_files( array( $url ) );
        }
        if ( function_exists( 'w3tc_flush_url' ) ) {
            w3tc_flush_url( $url );
        }
        if ( function_exists( 'wpsc_delete_url_cache' ) ) {
            wpsc_delete_url_cache( $url );
        }
    }

    public function handle_save() {
        $this->authorize_post( 'tlm_save_mapping' );
        $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
        $term_id  = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
        $page_id  = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
        $edit_key = isset( $_POST['edit_key'] ) ? sanitize_text_field( wp_unslash( $_POST['edit_key'] ) ) : '';
        $start    = isset( $_POST['starts_at'] ) ? sanitize_text_field( wp_unslash( $_POST['starts_at'] ) ) : '';
        $end      = isset( $_POST['ends_at'] ) ? sanitize_text_field( wp_unslash( $_POST['ends_at'] ) ) : '';
        $enabled  = isset( $_POST['enabled'] ) ? 1 : 0;

        if ( ! isset( $this->taxonomies()[ $taxonomy ] ) ) {
            $this->go_back( 'bad_term', $edit_key );
        }
        $term = get_term( $term_id, $taxonomy );
        if ( ! ( $term instanceof WP_Term ) || is_wp_error( $term ) ) {
            $this->go_back( 'bad_term', $edit_key );
        }
        if ( 'page' !== get_post_type( $page_id ) || 'publish' !== get_post_status( $page_id ) ) {
            $this->go_back( 'bad_page', $edit_key );
        }
        $start_time = $this->parse_local_date( $start );
        $end_time   = $this->parse_local_date( $end );
        if ( false === $start_time || false === $end_time ||
            ( null !== $start_time && null !== $end_time && $start_time >= $end_time ) ) {
            $this->go_back( 'bad_dates', $edit_key );
        }

        $key      = $this->mapping_key( $taxonomy, $term_id );
        $mappings = $this->get_mappings();
        if ( $edit_key ) {
            if ( $edit_key !== $key || ! isset( $mappings[ $key ] ) ) {
                $this->go_back( 'bad_edit' );
            }
        } elseif ( isset( $mappings[ $key ] ) ) {
            $this->go_back( 'duplicate', $key );
        }
        $before = isset( $mappings[ $key ] ) ? $mappings[ $key ] : null;
        $after = array(
            'taxonomy'   => $taxonomy,
            'term_id'    => $term_id,
            'page_id'    => $page_id,
            'enabled'    => $enabled,
            'starts_at'  => $start,
            'ends_at'    => $end,
            'updated_at' => current_datetime()->format( 'Y-m-d H:i:s' ),
        );
        $this->store_change( $key, $before, $after, $before ? 'updated' : 'created' );
        $this->go_back( $before ? 'updated' : 'created' );
    }

    public function handle_change() {
        $key    = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        $change = isset( $_POST['change'] ) ? sanitize_key( wp_unslash( $_POST['change'] ) ) : '';
        $this->authorize_post( 'tlm_change_' . $key . '_' . $change );
        if ( ! $this->parse_key( $key ) || ! in_array( $change, array( 'enable', 'disable', 'remove' ), true ) ) {
            $this->go_back( 'bad_edit' );
        }
        $mappings = $this->get_mappings();
        if ( ! isset( $mappings[ $key ] ) ) {
            $this->go_back( 'bad_edit' );
        }
        $before = $mappings[ $key ];
        $after  = $before;
        if ( 'remove' === $change ) {
            $after = null;
        } else {
            $after['enabled'] = ( 'enable' === $change ) ? 1 : 0;
            $after['updated_at'] = current_datetime()->format( 'Y-m-d H:i:s' );
        }
        $this->store_change( $key, $before, $after, $change );
        $this->go_back( $change );
    }

    public function handle_restore() {
        $id = isset( $_POST['history_id'] ) ? sanitize_text_field( wp_unslash( $_POST['history_id'] ) ) : '';
        $this->authorize_post( 'tlm_restore_' . $id );
        $entry = null;
        foreach ( $this->get_history() as $row ) {
            if ( isset( $row['id'] ) && $row['id'] === $id ) {
                $entry = $row;
                break;
            }
        }
        if ( ! $entry || ! $this->parse_key( $entry['key'] ) ) {
            $this->go_back( 'bad_edit' );
        }
        $target = isset( $entry['before'] ) ? $entry['before'] : null;
        if ( is_array( $target ) ) {
            $parts = $this->parse_key( $entry['key'] );
            if ( $target['taxonomy'] !== $parts[0] || (int) $target['term_id'] !== $parts[1] ||
                ! $this->get_term_for_mapping( $target ) || 'page' !== get_post_type( (int) $target['page_id'] ) ||
                'publish' !== get_post_status( (int) $target['page_id'] ) ) {
                $this->go_back( 'restore_invalid' );
            }
        }
        $mappings = $this->get_mappings();
        $before = isset( $mappings[ $entry['key'] ] ) ? $mappings[ $entry['key'] ] : null;
        $this->store_change( $entry['key'], $before, $target, 'restored', $id );
        $this->go_back( 'restored' );
    }

    private function action_label( $action ) {
        $labels = array(
            'created'  => 'ایجاد نگاشت',
            'updated'  => 'ویرایش نگاشت',
            'enable'   => 'فعال‌سازی',
            'disable'  => 'غیرفعال‌سازی',
            'remove'   => 'حذف نگاشت',
            'restored' => 'بازگردانی از تاریخچه',
        );
        return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
    }

    private function snapshot_label( $mapping ) {
        if ( ! $mapping || ! is_array( $mapping ) ) {
            return 'حالت عادی آرشیو تگ';
        }
        $output = $this->page_label( $mapping['page_id'] );
        $output .= empty( $mapping['enabled'] ) ? ' — غیرفعال' : ' — فعال';
        if ( ! empty( $mapping['starts_at'] ) ) {
            $output .= ' | شروع: ' . str_replace( 'T', ' ', $mapping['starts_at'] );
        }
        if ( ! empty( $mapping['ends_at'] ) ) {
            $output .= ' | پایان: ' . str_replace( 'T', ' ', $mapping['ends_at'] );
        }
        return $output;
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $mappings = $this->get_mappings();
        $history  = $this->get_history();
        $edit_key = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
        $editing  = $edit_key && $this->parse_key( $edit_key ) && isset( $mappings[ $edit_key ] ) ? $mappings[ $edit_key ] : null;
        $notice   = isset( $_GET['tlm_notice'] ) ? sanitize_key( wp_unslash( $_GET['tlm_notice'] ) ) : '';
        $notices = array(
            'created'         => array( 'success', 'نگاشت با موفقیت ایجاد شد.' ),
            'updated'         => array( 'success', 'نگاشت به‌روزرسانی شد.' ),
            'enable'          => array( 'success', 'نگاشت فعال شد.' ),
            'disable'         => array( 'success', 'نگاشت غیرفعال شد و آرشیو عادی نمایش داده می‌شود.' ),
            'remove'          => array( 'success', 'نگاشت حذف شد و آرشیو عادی نمایش داده می‌شود.' ),
            'restored'        => array( 'success', 'وضعیت قبلی از تاریخچه بازیابی شد.' ),
            'duplicate'       => array( 'error', 'این تگ از قبل نگاشت دارد. همان نگاشت را ویرایش کنید.' ),
            'bad_term'        => array( 'error', 'برچسب انتخاب‌شده معتبر نیست.' ),
            'bad_page'        => array( 'error', 'یک برگه منتشرشده و معتبر انتخاب کنید.' ),
            'bad_dates'       => array( 'error', 'تاریخ‌ها نامعتبرند یا تاریخ شروع قبل از پایان نیست.' ),
            'bad_edit'        => array( 'error', 'نگاشت انتخاب‌شده معتبر یا موجود نیست.' ),
            'restore_invalid' => array( 'error', 'بازگردانی ممکن نیست؛ برگه یا تگ قدیمی حذف یا از انتشار خارج شده است.' ),
        );
        $admin_url = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'tools.php' ) );
        ?>
        <div class="wrap tlm-admin" dir="rtl">
            <h1>مدیریت لندینگ تگ‌ها</h1>
            <p class="description">بدون تغییر آدرس تگ، محتوای یک برگه را در جای آرشیو آن نمایش دهید. با غیرفعال‌سازی، حذف یا رسیدن تاریخ پایان، آرشیو قبلی به‌صورت خودکار برمی‌گردد.</p>
            <?php if ( $notice && isset( $notices[ $notice ] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
            <?php endif; ?>
            <div class="tlm-panel">
                <h2><?php echo $editing ? 'ویرایش لندینگ تگ' : 'افزودن لندینگ جدید'; ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tlm-mapping-form">
                    <input type="hidden" name="action" value="tlm_save_mapping">
                    <input type="hidden" name="edit_key" value="<?php echo esc_attr( $editing ? $edit_key : '' ); ?>">
                    <?php wp_nonce_field( 'tlm_save_mapping' ); ?>
                    <div class="tlm-grid">
                        <div class="tlm-field">
                            <label for="tlm-taxonomy">نوع تگ</label>
                            <?php if ( $editing ) : ?>
                                <input type="text" value="<?php echo esc_attr( $this->taxonomies()[ $editing['taxonomy'] ] ); ?>" readonly>
                                <input type="hidden" name="taxonomy" value="<?php echo esc_attr( $editing['taxonomy'] ); ?>" id="tlm-taxonomy">
                            <?php else : ?>
                                <select name="taxonomy" id="tlm-taxonomy" required>
                                    <?php foreach ( $this->taxonomies() as $taxonomy => $label ) : ?>
                                        <option value="<?php echo esc_attr( $taxonomy ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="tlm-field">
                            <label for="tlm-term-search">تگ موردنظر</label>
                            <input type="text" autocomplete="off" id="tlm-term-search" placeholder="نام تگ را جست‌وجو کنید…" value="<?php echo esc_attr( $editing ? ( $this->get_term_for_mapping( $editing ) ? $this->get_term_for_mapping( $editing )->name : '' ) : '' ); ?>" <?php echo $editing ? 'readonly' : ''; ?> required>
                            <input type="hidden" name="term_id" id="tlm-term-id" value="<?php echo esc_attr( $editing ? (int) $editing['term_id'] : '' ); ?>">
                            <?php if ( ! $editing ) : ?><small>از فهرست پیشنهادها، تگ را انتخاب کنید.</small><?php endif; ?>
                        </div>
                        <div class="tlm-field">
                            <label for="tlm-page-search">برگه لندینگ</label>
                            <input type="text" autocomplete="off" id="tlm-page-search" placeholder="نام برگه منتشرشده را جست‌وجو کنید…" value="<?php echo esc_attr( $editing ? get_the_title( (int) $editing['page_id'] ) : '' ); ?>" required>
                            <input type="hidden" name="page_id" id="tlm-page-id" value="<?php echo esc_attr( $editing ? (int) $editing['page_id'] : '' ); ?>">
                            <small>المنتور و محتوای معمولی وردپرس پشتیبانی می‌شوند.</small>
                        </div>
                        <div class="tlm-field tlm-field-check">
                            <label><input type="checkbox" name="enabled" value="1" <?php checked( $editing ? ! empty( $editing['enabled'] ) : true ); ?>> نگاشت فعال باشد</label>
                            <small>برای بازگشت فوری آرشیو قبلی، این گزینه را غیرفعال کنید.</small>
                        </div>
                        <div class="tlm-field">
                            <label for="tlm-starts">شروع پروموشن (اختیاری)</label>
                            <input type="datetime-local" id="tlm-starts" name="starts_at" value="<?php echo esc_attr( $editing ? $editing['starts_at'] : '' ); ?>">
                        </div>
                        <div class="tlm-field">
                            <label for="tlm-ends">پایان پروموشن (اختیاری)</label>
                            <input type="datetime-local" id="tlm-ends" name="ends_at" value="<?php echo esc_attr( $editing ? $editing['ends_at'] : '' ); ?>">
                        </div>
                    </div>
                    <p class="description">زمان‌ها براساس منطقه زمانی سایت وردپرس هستند (<?php echo esc_html( wp_timezone_string() ); ?>). پس از پایان زمان، بدون حذف تاریخچه، آرشیو استاندارد برمی‌گردد.</p>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php echo $editing ? 'ذخیره تغییرات' : 'افزودن نگاشت'; ?></button>
                        <?php if ( $editing ) : ?><a class="button" href="<?php echo esc_url( $admin_url ); ?>">انصراف از ویرایش</a><?php endif; ?>
                    </p>
                </form>
            </div>

            <div class="tlm-panel">
                <h2>نگاشت‌های فعلی <span class="tlm-count"><?php echo count( $mappings ); ?></span></h2>
                <div class="tlm-table-wrap">
                    <table class="widefat striped tlm-table">
                        <thead><tr><th>تگ</th><th>برگه مقصد</th><th>وضعیت</th><th>بازه زمانی</th><th>اقدامات</th></tr></thead>
                        <tbody>
                        <?php if ( ! $mappings ) : ?>
                            <tr><td colspan="5">هنوز نگاشتی تعریف نشده است.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $mappings as $key => $mapping ) :
                                $status = $this->status( $mapping );
                                $term_url = get_term_link( (int) $mapping['term_id'], $mapping['taxonomy'] );
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $this->term_label( $mapping['taxonomy'], $mapping['term_id'] ) ); ?></strong><div class="tlm-subtext"><?php echo esc_html( $key ); ?></div>
                                        <?php if ( ! is_wp_error( $term_url ) ) : ?><a href="<?php echo esc_url( $term_url ); ?>" target="_blank" rel="noopener noreferrer">مشاهده آدرس تگ</a><?php endif; ?>
                                    </td>
                                    <td><a href="<?php echo esc_url( get_edit_post_link( (int) $mapping['page_id'], '' ) ); ?>"><?php echo esc_html( $this->page_label( $mapping['page_id'] ) ); ?></a></td>
                                    <td><span class="tlm-status tlm-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $this->status_label( $status ) ); ?></span></td>
                                    <td><small>شروع: <?php echo esc_html( $mapping['starts_at'] ? str_replace( 'T', ' ', $mapping['starts_at'] ) : 'همین حالا' ); ?><br>پایان: <?php echo esc_html( $mapping['ends_at'] ? str_replace( 'T', ' ', $mapping['ends_at'] ) : 'بدون محدودیت' ); ?></small></td>
                                    <td class="tlm-actions">
                                        <a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', $key, $admin_url ) ); ?>">ویرایش</a>
                                        <?php if ( ! empty( $mapping['enabled'] ) ) : ?>
                                            <?php $this->change_form( $key, 'disable', 'غیرفعال‌سازی' ); ?>
                                        <?php else : ?>
                                            <?php $this->change_form( $key, 'enable', 'فعال‌سازی' ); ?>
                                        <?php endif; ?>
                                        <?php $this->change_form( $key, 'remove', 'حذف و بازگشت به آرشیو', true ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tlm-panel">
                <h2>تاریخچه تغییرات <span class="tlm-count"><?php echo count( $history ); ?></span></h2>
                <p class="description">حداکثر ۳۰۰ تغییر آخر ذخیره می‌شود. بازگردانی، وضعیت <strong>پیش از همان تغییر</strong> را روی نگاشت فعلی اعمال می‌کند و خودش نیز در تاریخچه ثبت می‌شود.</p>
                <?php
                $per_page = 20;
                $current_page = isset( $_GET['tlm_paged'] ) ? max( 1, absint( $_GET['tlm_paged'] ) ) : 1;
                $pages = max( 1, (int) ceil( count( $history ) / $per_page ) );
                $current_page = min( $current_page, $pages );
                $rows = array_slice( $history, ( $current_page - 1 ) * $per_page, $per_page );
                ?>
                <div class="tlm-table-wrap">
                    <table class="widefat striped tlm-table">
                        <thead><tr><th>تاریخ</th><th>تگ</th><th>عملیات</th><th>قبل از تغییر</th><th>بعد از تغییر</th><th>کاربر</th><th>بازگردانی</th></tr></thead>
                        <tbody>
                        <?php if ( ! $rows ) : ?>
                            <tr><td colspan="7">هنوز تغییری ثبت نشده است.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $rows as $row ) :
                                $parts = $this->parse_key( $row['key'] );
                                $user = get_userdata( (int) $row['user_id'] );
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $row['at'] ); ?></td>
                                    <td><?php echo esc_html( $parts ? $this->term_label( $parts[0], $parts[1] ) : $row['key'] ); ?></td>
                                    <td><?php echo esc_html( $this->action_label( $row['action'] ) ); ?></td>
                                    <td class="tlm-snapshot"><?php echo esc_html( $this->snapshot_label( $row['before'] ) ); ?></td>
                                    <td class="tlm-snapshot"><?php echo esc_html( $this->snapshot_label( $row['after'] ) ); ?></td>
                                    <td><?php echo esc_html( $user ? $user->display_name : 'کاربر حذف‌شده' ); ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tlm-confirm" data-confirm="وضعیت پیش از این تغییر، جایگزین وضعیت فعلی این تگ می‌شود. ادامه می‌دهید؟">
                                            <input type="hidden" name="action" value="tlm_restore_mapping">
                                            <input type="hidden" name="history_id" value="<?php echo esc_attr( $row['id'] ); ?>">
                                            <?php wp_nonce_field( 'tlm_restore_' . $row['id'] ); ?>
                                            <button type="submit" class="button button-small">بازگردانی</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ( $pages > 1 ) : ?>
                    <div class="tablenav"><div class="tablenav-pages">
                    <?php echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        'base'      => esc_url_raw( add_query_arg( 'tlm_paged', '%#%', $admin_url ) ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => $pages,
                        'prev_text' => 'قبلی',
                        'next_text' => 'بعدی',
                    ) ); ?>
                    </div></div>
                <?php endif; ?>
            </div>
            <p class="description">نکته: عنوان سئو و تنظیمات ایندکس، همچنان متعلق به آرشیو تگ هستند. اگر افزونه کش یا CDN دارید، در زمان شروع یا پایان کمپین از پاک‌شدن کش آدرس تگ اطمینان حاصل کنید.</p>
        </div>
        <?php
    }

    private function change_form( $key, $action, $label, $confirm = false ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php if ( $confirm ) : ?>class="tlm-confirm" data-confirm="این نگاشت حذف می‌شود و آدرس تگ به آرشیو معمولی بازمی‌گردد. تاریخچه نگه داشته می‌شود. ادامه می‌دهید؟"<?php endif; ?>>
            <input type="hidden" name="action" value="tlm_change_mapping">
            <input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
            <input type="hidden" name="change" value="<?php echo esc_attr( $action ); ?>">
            <?php wp_nonce_field( 'tlm_change_' . $key . '_' . $action ); ?>
            <button type="submit" class="button button-small <?php echo $confirm ? 'tlm-danger' : ''; ?>"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    public static function on_deactivation() {
        $mappings = get_option( self::OPTION_MAPPINGS, array() );
        if ( ! is_array( $mappings ) ) {
            return;
        }
        foreach ( $mappings as $mapping ) {
            if ( ! isset( $mapping['taxonomy'], $mapping['term_id'] ) ) {
                continue;
            }
            foreach ( array( 'starts_at', 'ends_at' ) as $field ) {
                wp_clear_scheduled_hook( 'tlm_refresh_archive_cache', array( $mapping['taxonomy'], (int) $mapping['term_id'], $field ) );
            }
            if ( taxonomy_exists( $mapping['taxonomy'] ) ) {
                $url = get_term_link( (int) $mapping['term_id'], $mapping['taxonomy'] );
                if ( ! is_wp_error( $url ) ) {
                    do_action( 'litespeed_purge_url', $url );
                    if ( function_exists( 'rocket_clean_files' ) ) {
                        rocket_clean_files( array( $url ) );
                    }
                }
            }
        }
    }
}

$GLOBALS['tlm_plugin_instance'] = new TLM_Tag_Landing_Mapper();
register_deactivation_hook( __FILE__, array( 'TLM_Tag_Landing_Mapper', 'on_deactivation' ) );
