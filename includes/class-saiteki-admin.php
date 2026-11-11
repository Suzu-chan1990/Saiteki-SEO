<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin screen: dashboard with section tiles, tabbed sub-pages, instant-saving switches.
 */
class Saiteki_Admin {
    const PAGE  = 'saiteki-dashboard';
    const NONCE = 'saiteki_admin';

    public static function init() {
        // Both classes provide helpers the admin screen needs, even when their module is switched off.
        require_once SAITEKI_PATH . 'includes/class-saiteki-sitemap.php';
        require_once SAITEKI_PATH . 'includes/class-saiteki-indexing.php';

        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'load_assets' ) );
        add_action( 'wp_ajax_saiteki_toggle', array( __CLASS__, 'ajax_toggle' ) );
        add_action( 'wp_ajax_saiteki_save', array( __CLASS__, 'ajax_save' ) );
        add_action( 'wp_ajax_saiteki_action', array( __CLASS__, 'ajax_action' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( SAITEKI_FILE ), array( __CLASS__, 'action_links' ) );
    }

    public static function register_menu() {
        add_menu_page( __( 'Saiteki SEO', 'saiteki' ), __( 'Saiteki SEO', 'saiteki' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-superhero-alt', 101 );
    }

    public static function action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'saiteki' ) . '</a>' );
        return $links;
    }

    public static function load_assets( $hook ) {
        if ( $hook !== 'toplevel_page_' . self::PAGE ) return;

        wp_enqueue_style( 'saiteki-admin', SAITEKI_URL . 'css/admin.css', array(), SAITEKI_VERSION );
        wp_enqueue_script( 'saiteki-admin', SAITEKI_URL . 'js/admin.js', array(), SAITEKI_VERSION, true );
        wp_localize_script( 'saiteki-admin', 'SaitekiAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE ),
            'homeUrl' => trailingslashit( home_url( '/' ) ),
            'i18n'    => array(
                'saved'   => __( 'Saved', 'saiteki' ),
                'error'   => __( 'Error', 'saiteki' ),
                'working' => __( 'Working…', 'saiteki' ),
                'copied'  => __( 'Copied', 'saiteki' ),
            ),
        ) );
    }

    private static function url( string $tab = 'dashboard' ): string {
        $args = array( 'page' => self::PAGE );
        if ( $tab !== 'dashboard' ) $args['tab'] = $tab;
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    private static function nav_groups(): array {
        return array(
            'sitemap'  => array(
                'title' => __( 'XML Sitemap', 'saiteki' ),
                'icon'  => 'dashicons-networking',
                'desc'  => __( 'Custom path, file names, image and video extensions', 'saiteki' ),
                'tabs'  => array(
                    'sitemap'         => __( 'Path & Files', 'saiteki' ),
                    'sitemap-content' => __( 'Content', 'saiteki' ),
                ),
            ),
            'frontend' => array(
                'title' => __( 'Frontend SEO', 'saiteki' ),
                'icon'  => 'dashicons-visibility',
                'desc'  => __( 'Meta tags, Open Graph, Twitter cards, JSON-LD, titles', 'saiteki' ),
                'tabs'  => array(
                    'meta'   => __( 'Meta & Social', 'saiteki' ),
                    'schema' => __( 'Schema & Titles', 'saiteki' ),
                ),
            ),
            'indexing' => array(
                'title' => __( 'Instant Indexing', 'saiteki' ),
                'icon'  => 'dashicons-update',
                'desc'  => __( 'IndexNow, Google Indexing API with key rotation, Hydro bridge', 'saiteki' ),
                'tabs'  => array(
                    'indexing' => __( 'IndexNow', 'saiteki' ),
                    'google'   => __( 'Google Indexing API', 'saiteki' ),
                    'hydro'    => __( 'Hydro Bridge', 'saiteki' ),
                ),
            ),
            'tools'    => array(
                'title' => __( 'Tools', 'saiteki' ),
                'icon'  => 'dashicons-admin-tools',
                'desc'  => __( 'SEO health audit, system information', 'saiteki' ),
                'tabs'  => array(
                    'health' => __( 'Health Audit', 'saiteki' ),
                    'system' => __( 'System', 'saiteki' ),
                ),
            ),
        );
    }

    // =================================================================
    // AJAX
    // =================================================================

    private static function ajax_guard() {
        check_ajax_referer( self::NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'saiteki' ) ), 403 );
        }
    }

    /** Instant-saving switches. */
    public static function ajax_toggle() {
        self::ajax_guard();

        $key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
        if ( ! in_array( $key, Saiteki_Core::TOGGLES, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown setting.', 'saiteki' ) ), 400 );
        }

        $value = ( isset( $_POST['val'] ) && '1' === $_POST['val'] ) ? '1' : '0';
        Saiteki_Core::update_options( array( $key => $value ) );

        if ( str_starts_with( $key, 'sitemap_' ) ) {
            delete_transient( Saiteki_Sitemap::INDEX_CACHE );
        }
        if ( str_starts_with( $key, 'enable_health_' ) ) {
            delete_transient( 'saiteki_health' );
        }

        wp_send_json_success( array( 'message' => __( 'Saved', 'saiteki' ) ) );
    }

    /** Forms. */
    public static function ajax_save() {
        self::ajax_guard();

        $section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
        $options = Saiteki_Core::get_options();

        switch ( $section ) {
            case 'sitemap_path':
                $dir    = Saiteki_Sitemap::sanitize_dir( isset( $_POST['sitemap_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['sitemap_dir'] ) ) : '' );
                $index  = Saiteki_Sitemap::sanitize_index( isset( $_POST['sitemap_index'] ) ? sanitize_text_field( wp_unslash( $_POST['sitemap_index'] ) ) : '' );
                $prefix = Saiteki_Sitemap::sanitize_prefix( isset( $_POST['sitemap_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['sitemap_prefix'] ) ) : '' );

                if ( Saiteki_Sitemap::names_collide( $index, $prefix ) ) {
                    wp_send_json_error( array( 'message' => __( 'The index file name collides with the names of the sub-sitemaps. Choose a different index name or prefix.', 'saiteki' ) ), 400 );
                }

                $changes = array( 'sitemap_dir' => $dir, 'sitemap_index' => $index, 'sitemap_prefix' => $prefix );
                $moved   = $dir !== $options['sitemap_dir'] || $index !== $options['sitemap_index'] || $prefix !== $options['sitemap_prefix'];
                if ( $moved ) {
                    // Remember the previous location so it can be redirected.
                    $changes['sitemap_prev_dir']    = $options['sitemap_dir'];
                    $changes['sitemap_prev_index']  = $options['sitemap_index'];
                    $changes['sitemap_prev_prefix'] = $options['sitemap_prefix'];
                }
                $options = Saiteki_Core::update_options( $changes );
                delete_transient( Saiteki_Sitemap::INDEX_CACHE );

                wp_send_json_success( array(
                    'message' => $moved
                        /* translators: %s: sitemap index URL */
                        ? sprintf( __( 'Sitemap moved to %s', 'saiteki' ), Saiteki_Sitemap::index_url( $options ) )
                        : __( 'Saved', 'saiteki' ),
                    'reload'  => $moved,
                ) );
                break;

            case 'sitemap_content':
                $limit = isset( $_POST['sitemap_limit'] ) ? absint( wp_unslash( $_POST['sitemap_limit'] ) ) : 5000;
                $limit = min( 20000, max( 100, $limit ) );
                Saiteki_Core::update_options( array( 'sitemap_limit' => $limit ) );
                delete_transient( Saiteki_Sitemap::INDEX_CACHE );
                wp_send_json_success( array( 'message' => __( 'Saved', 'saiteki' ), 'reload' => $limit !== (int) $options['sitemap_limit'] ) );
                break;

            case 'titles':
                $suffix = isset( $_POST['title_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['title_suffix'] ) ) : '';
                Saiteki_Core::update_options( array( 'title_suffix' => mb_substr( $suffix, 0, 80 ) ) );
                wp_send_json_success( array( 'message' => __( 'Saved', 'saiteki' ) ) );
                break;

            case 'google_keys':
                // wp_unslash() exactly once and no text sanitizing: the "\n" escapes inside private keys must survive.
                $raw = isset( $_POST['google_keys'] ) ? (string) wp_unslash( $_POST['google_keys'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $add = isset( $_POST['google_mode'] ) && 'add' === $_POST['google_mode'];

                list( $valid, $skipped ) = Saiteki_Indexing::parse_google_input( $raw );
                if ( empty( $valid ) ) {
                    wp_send_json_error( array( 'message' => __( 'Nothing was saved: no valid service account JSON with a readable private key was found. The existing keys were kept.', 'saiteki' ) ), 400 );
                }

                if ( $add ) {
                    $merged = array();
                    foreach ( array_merge( Saiteki_Indexing::get_google_keys(), $valid ) as $sa ) {
                        $merged[ strtolower( (string) ( $sa['client_email'] ?? '' ) ) ] = $sa;
                    }
                    Saiteki_Indexing::save_google_keys( array_values( $merged ), false );
                    $total = count( $merged );
                } else {
                    Saiteki_Indexing::save_google_keys( $valid, true ); // replacing the set restarts the rotation
                    $total = count( $valid );
                }

                /* translators: %d: number of keys */
                $message = sprintf( _n( '%d key is active.', '%d keys are active.', $total, 'saiteki' ), $total );
                if ( $skipped > 0 ) {
                    /* translators: %d: number of skipped keys */
                    $message .= ' ' . sprintf( _n( '%d entry was skipped because it is not a valid service account JSON.', '%d entries were skipped because they are not valid service account JSONs.', $skipped, 'saiteki' ), $skipped );
                }
                wp_send_json_success( array( 'message' => $message, 'reload' => true ) );
                break;
        }

        wp_send_json_error( array( 'message' => __( 'Unknown form.', 'saiteki' ) ), 400 );
    }

    /** Buttons. */
    public static function ajax_action() {
        self::ajax_guard();

        $do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
        $pos = isset( $_POST['pos'] ) ? absint( wp_unslash( $_POST['pos'] ) ) : 0;

        switch ( $do ) {
            case 'sitemap_test':
                $url      = Saiteki_Sitemap::index_url();
                $response = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
                if ( is_wp_error( $response ) ) {
                    /* translators: %s: error message */
                    wp_send_json_error( array( 'message' => sprintf( __( 'The server could not request its own sitemap: %s', 'saiteki' ), $response->get_error_message() ) ) );
                }
                $code = (int) wp_remote_retrieve_response_code( $response );
                $ok   = 200 === $code && str_contains( (string) wp_remote_retrieve_body( $response ), '<sitemapindex' );
                if ( $ok ) {
                    $count = substr_count( (string) wp_remote_retrieve_body( $response ), '<sitemap>' );
                    /* translators: %d: number of sitemap files */
                    wp_send_json_success( array( 'message' => sprintf( _n( 'Reachable: HTTP 200, valid sitemap index with %d file.', 'Reachable: HTTP 200, valid sitemap index with %d files.', $count, 'saiteki' ), $count ) ) );
                }
                /* translators: %d: HTTP status code */
                wp_send_json_error( array( 'message' => sprintf( __( 'Not reachable: HTTP %d. If the web server answers .xml requests itself, they must be passed on to WordPress (index.php).', 'saiteki' ), $code ) ) );
                break;

            case 'sitemap_flush':
                delete_transient( Saiteki_Sitemap::INDEX_CACHE );
                wp_send_json_success( array( 'message' => __( 'The sitemap index will be rebuilt on the next request.', 'saiteki' ) ) );
                break;

            case 'sitemap_forget_old':
                Saiteki_Core::update_options( array( 'sitemap_prev_dir' => '', 'sitemap_prev_index' => '', 'sitemap_prev_prefix' => '' ) );
                wp_send_json_success( array( 'message' => __( 'The previous location is no longer redirected.', 'saiteki' ), 'reload' => true ) );
                break;

            case 'indexnow_regen':
                Saiteki_Indexing::regenerate_indexnow_key();
                wp_send_json_success( array( 'message' => __( 'A new IndexNow key was generated.', 'saiteki' ), 'reload' => true ) );
                break;

            case 'indexnow_test':
                $response = Saiteki_Indexing::ping_indexnow( home_url( '/' ), true );
                if ( null === $response ) {
                    wp_send_json_error( array( 'message' => __( 'No IndexNow key available.', 'saiteki' ) ) );
                }
                if ( is_wp_error( $response ) ) {
                    wp_send_json_error( array( 'message' => $response->get_error_message() ) );
                }
                $code = (int) wp_remote_retrieve_response_code( $response );
                if ( 200 === $code || 202 === $code ) {
                    /* translators: %d: HTTP status code */
                    wp_send_json_success( array( 'message' => sprintf( __( 'IndexNow accepted the home page (HTTP %d).', 'saiteki' ), $code ) ) );
                }
                if ( 429 === $code ) {
                    wp_send_json_error( array( 'message' => __( 'IndexNow answered with HTTP 429 (too many requests). The key and the setup are fine: IndexNow is throttling this site at the moment, usually after many single requests in a short time, for example during a bulk import. Try again later.', 'saiteki' ) ) );
                }
                if ( 403 === $code ) {
                    wp_send_json_error( array( 'message' => __( 'IndexNow answered with HTTP 403: the key file could not be verified. Open the key file URL and check that it shows the key.', 'saiteki' ) ) );
                }
                if ( 422 === $code ) {
                    wp_send_json_error( array( 'message' => __( 'IndexNow answered with HTTP 422: the URL does not belong to this host, or the key does not match.', 'saiteki' ) ) );
                }
                /* translators: %d: HTTP status code */
                wp_send_json_error( array( 'message' => sprintf( __( 'IndexNow answered with HTTP %d.', 'saiteki' ), $code ) ) );
                break;

            case 'google_test':
                $result = Saiteki_Indexing::test_google_key( $pos );
                if ( is_wp_error( $result ) ) {
                    wp_send_json_error( array( 'message' => $result->get_error_message() ) );
                }
                wp_send_json_success( array( 'message' => __( 'OK: Google issued an access token for this key.', 'saiteki' ) ) );
                break;

            case 'google_remove':
                $keys = Saiteki_Indexing::get_google_keys();
                if ( ! isset( $keys[ $pos ] ) ) {
                    wp_send_json_error( array( 'message' => __( 'This key does not exist any more.', 'saiteki' ) ) );
                }
                delete_transient( 'saiteki_gtoken_' . md5( (string) ( $keys[ $pos ]['client_email'] ?? '' ) ) );
                unset( $keys[ $pos ] );
                Saiteki_Indexing::save_google_keys( $keys, false );
                wp_send_json_success( array( 'message' => __( 'Key removed.', 'saiteki' ), 'reload' => true ) );
                break;

            case 'google_clear':
                foreach ( Saiteki_Indexing::get_google_keys() as $sa ) {
                    delete_transient( 'saiteki_gtoken_' . md5( (string) ( $sa['client_email'] ?? '' ) ) );
                }
                Saiteki_Indexing::save_google_keys( array() );
                wp_send_json_success( array( 'message' => __( 'All Google keys were removed.', 'saiteki' ), 'reload' => true ) );
                break;

            case 'health_refresh':
                delete_transient( 'saiteki_health' );
                wp_send_json_success( array( 'message' => __( 'Audit refreshed.', 'saiteki' ), 'reload' => true ) );
                break;
        }

        wp_send_json_error( array( 'message' => __( 'Unknown action.', 'saiteki' ) ), 400 );
    }

    // =================================================================
    // Data helpers
    // =================================================================

    /**
     * Health audit counts, cached for 15 minutes. Only enabled checks run a query.
     */
    private static function health( array $o ): array {
        global $wpdb;
        if ( $o['enable_health_thumbs'] !== '1' && $o['enable_health_desc'] !== '1' ) {
            return array( 'thumbs' => null, 'desc' => null, 'time' => 0 );
        }

        $cached = get_transient( 'saiteki_health' );
        if ( is_array( $cached ) ) return $cached;

        $data = array( 'thumbs' => null, 'desc' => null, 'time' => time() );

        if ( $o['enable_health_thumbs'] === '1' ) {
            // Published posts without a featured image.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $data['thumbs'] = (int) $wpdb->get_var( "SELECT COUNT(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id') WHERE p.post_type = 'post' AND p.post_status = 'publish' AND pm.meta_id IS NULL" );
        }
        if ( $o['enable_health_desc'] === '1' ) {
            // Published posts with very short content and excerpt.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $data['desc'] = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND CHAR_LENGTH(post_content) < 100 AND CHAR_LENGTH(post_excerpt) < 100" );
        }

        set_transient( 'saiteki_health', $data, 15 * MINUTE_IN_SECONDS );
        return $data;
    }

    /**
     * Other active SEO plugins that print the same tags.
     */
    private static function conflicts(): array {
        $found = array();
        if ( defined( 'WPSEO_VERSION' ) ) $found[] = 'Yoast SEO';
        if ( class_exists( 'RankMath' ) ) $found[] = 'Rank Math';
        if ( defined( 'AIOSEO_VERSION' ) ) $found[] = 'All in One SEO';
        if ( defined( 'SEOPRESS_VERSION' ) ) $found[] = 'SEOPress';
        if ( function_exists( 'the_seo_framework' ) ) $found[] = 'The SEO Framework';
        if ( defined( 'SLIM_SEO_VER' ) ) $found[] = 'Slim SEO';
        return $found;
    }

    /**
     * Score, status and recommendations for the dashboard.
     */
    private static function status( array $o ): array {
        $conflicts = self::conflicts();
        $key_ok    = Saiteki_Indexing::indexnow_key() !== '';
        $keys_bad  = Saiteki_Indexing::google_keys_unreadable();

        $checks = array(
            array( 20, '1' === (string) get_option( 'blog_public' ), 'important', __( 'Search engines are blocked', 'saiteki' ), __( 'Settings > Reading discourages search engines from indexing this site. Nothing else matters until this is switched off.', 'saiteki' ), admin_url( 'options-reading.php' ), __( 'Open Reading settings', 'saiteki' ) ),
            array( 10, (string) get_option( 'permalink_structure' ) !== '', 'important', __( 'Plain permalinks are active', 'saiteki' ), __( 'The sitemap and readable URLs need a permalink structure.', 'saiteki' ), admin_url( 'options-permalink.php' ), __( 'Open Permalinks', 'saiteki' ) ),
            array( 20, $o['enable_sitemap_cleaner'] === '1', 'important', __( 'Enable the XML sitemap', 'saiteki' ), __( 'Without a sitemap, search engines discover new videos much more slowly.', 'saiteki' ), self::url( 'sitemap' ), __( 'Review sitemap', 'saiteki' ) ),
            array( 10, empty( $conflicts ), 'important', __( 'Another SEO plugin is active', 'saiteki' ), sprintf( /* translators: %s: plugin names */ __( '%s prints the same tags. Disable the overlapping features to avoid duplicates.', 'saiteki' ), implode( ', ', $conflicts ) ), admin_url( 'plugins.php' ), __( 'Open Plugins', 'saiteki' ) ),
            array( 5, $o['sitemap_robots'] === '1', 'recommended', __( 'Announce the sitemap in robots.txt', 'saiteki' ), __( 'Crawlers find the sitemap on their own when robots.txt points to it.', 'saiteki' ), self::url( 'sitemap' ), __( 'Review sitemap', 'saiteki' ) ),
            array( 10, $o['enable_schema'] === '1', 'recommended', __( 'Enable JSON-LD schema', 'saiteki' ), __( 'VideoObject markup is required for video rich results.', 'saiteki' ), self::url( 'schema' ), __( 'Review schema', 'saiteki' ) ),
            array( 5, $o['enable_twitter_cards'] === '1', 'optional', __( 'Twitter player cards are off', 'saiteki' ), __( 'Optional: shared links show a large image instead of an inline player.', 'saiteki' ), self::url( 'meta' ), __( 'Configure', 'saiteki' ) ),
            array( 10, $o['enable_api_indexing'] === '1', 'recommended', __( 'Enable instant indexing', 'saiteki' ), __( 'New videos are reported to search engines the moment they are published.', 'saiteki' ), self::url( 'indexing' ), __( 'Review indexing', 'saiteki' ) ),
            array( 5, $key_ok, 'recommended', __( 'IndexNow key is missing', 'saiteki' ), __( 'Generate a new key so IndexNow requests can be verified.', 'saiteki' ), self::url( 'indexing' ), __( 'Review IndexNow', 'saiteki' ) ),
            array( 5, ! $keys_bad, 'important', __( 'Google keys cannot be decrypted', 'saiteki' ), __( 'The stored keys were encrypted with different wp-config.php salts. Paste the service account JSON files again.', 'saiteki' ), self::url( 'google' ), __( 'Review keys', 'saiteki' ) ),
        );

        $score = 0;
        $recs  = array();
        $order = array( 'important' => 0, 'recommended' => 1, 'optional' => 2 );
        foreach ( $checks as $c ) {
            if ( $c[1] ) {
                $score += $c[0];
            } else {
                $recs[] = array( 'level' => $c[2], 'title' => $c[3], 'desc' => $c[4], 'url' => $c[5], 'action' => $c[6] );
            }
        }
        usort( $recs, static fn( $a, $b ) => $order[ $a['level'] ] <=> $order[ $b['level'] ] );

        if ( $score >= 85 ) {
            $name  = __( 'OPTIMAL', 'saiteki' );
            $desc  = __( 'The core SEO modules are active and no critical condition was detected.', 'saiteki' );
            $class = '';
            $color = '#10b981';
        } elseif ( $score >= 65 ) {
            $name  = __( 'ATTENTION', 'saiteki' );
            $desc  = __( 'Some modules are switched off or need a look.', 'saiteki' );
            $class = 'stk-score-warn';
            $color = '#f59e0b';
        } else {
            $name  = __( 'CRITICAL', 'saiteki' );
            $desc  = __( 'Important SEO basics are missing. Review the recommendations below.', 'saiteki' );
            $class = 'stk-score-danger';
            $color = '#ef4444';
        }

        return array( 'score' => $score, 'name' => $name, 'desc' => $desc, 'class' => $class, 'color' => $color, 'recs' => $recs );
    }

    // =================================================================
    // Render helpers
    // =================================================================

    private static function toggle( array $o, string $key, string $label, string $desc = '' ) {
        ?>
        <div class="stk-switch-row">
            <div class="stk-switch-text">
                <span class="stk-switch-label"><?php echo esc_html( $label ); ?></span>
                <?php if ( $desc !== '' ) : ?><span class="stk-switch-desc"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
            </div>
            <label class="stk-switch">
                <input type="checkbox" data-stk-toggle="<?php echo esc_attr( $key ); ?>" <?php checked( $o[ $key ], '1' ); ?>>
                <span class="stk-slider"></span>
            </label>
        </div>
        <?php
    }

    private static function url_row( string $label, string $url ) {
        ?>
        <div class="stk-url-row">
            <span class="stk-url-label"><?php echo esc_html( $label ); ?></span>
            <code class="stk-url"><?php echo esc_html( $url ); ?></code>
            <button type="button" class="button button-secondary stk-mini" data-stk-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'saiteki' ); ?></button>
            <a class="button button-secondary stk-mini" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'saiteki' ); ?></a>
        </div>
        <?php
    }

    private static function action_button( string $do, string $label, array $args = array() ) {
        $class = empty( $args['primary'] ) ? 'button-secondary' : 'button-primary';
        if ( ! empty( $args['danger'] ) ) $class .= ' stk-danger';
        if ( ! empty( $args['mini'] ) ) $class .= ' stk-mini';
        printf(
            '<button type="button" class="button %1$s" data-stk-do="%2$s"%3$s%4$s%5$s>%6$s</button>',
            esc_attr( $class ),
            esc_attr( $do ),
            isset( $args['pos'] ) ? ' data-stk-pos="' . esc_attr( (string) (int) $args['pos'] ) . '"' : '',
            ! empty( $args['confirm'] ) ? ' data-stk-confirm="' . esc_attr( $args['confirm'] ) . '"' : '',
            ! empty( $args['result'] ) ? ' data-stk-result="' . esc_attr( $args['result'] ) . '"' : '',
            esc_html( $label )
        );
    }

    private static function module_off_notice( bool $enabled, string $tab, string $text ) {
        if ( $enabled ) return;
        echo '<div class="stk-note stk-note-warn">' . esc_html( $text ) . ' <a href="' . esc_url( self::url( $tab ) ) . '">' . esc_html__( 'Switch it on', 'saiteki' ) . '</a></div>';
    }

    // =================================================================
    // Page
    // =================================================================

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only
        $groups = self::nav_groups();
        $o      = Saiteki_Core::get_options();

        $current_group = null;
        foreach ( $groups as $g_id => $group ) {
            if ( isset( $group['tabs'][ $tab ] ) ) {
                $current_group = $g_id;
                break;
            }
        }
        if ( null === $current_group ) $tab = 'dashboard';

        $banner = file_exists( SAITEKI_PATH . 'assets/banner-772x250.png' );
        ?>
        <div id="stk-toast" role="status" aria-live="polite"></div>
        <div class="stk-wrap">
            <div class="stk-header<?php echo $banner ? ' stk-header-brand' : ''; ?>">
                <div class="stk-title">
                    <?php if ( $banner ) : ?>
                        <img class="stk-banner" src="<?php echo esc_url( SAITEKI_URL . 'assets/banner-772x250.png' ); ?>"<?php if ( file_exists( SAITEKI_PATH . 'assets/banner-1544x500.png' ) ) : ?> srcset="<?php echo esc_url( SAITEKI_URL . 'assets/banner-772x250.png' ); ?> 1x, <?php echo esc_url( SAITEKI_URL . 'assets/banner-1544x500.png' ); ?> 2x"<?php endif; ?> width="772" height="250" alt="Saiteki SEO">
                    <?php else : ?>
                        <span class="stk-kanji">最適</span> Saiteki SEO
                    <?php endif; ?>
                </div>
                <div class="stk-badge">v<?php echo esc_html( SAITEKI_VERSION ); ?></div>
            </div>

            <?php if ( 'dashboard' === $tab ) : ?>
                <?php self::render_dashboard( $o, $groups ); ?>
            <?php else : ?>
                <div class="stk-inner-nav">
                    <a href="<?php echo esc_url( self::url() ); ?>" class="stk-back-btn"><span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Dashboard', 'saiteki' ); ?></a>
                    <div class="stk-horizontal-tabs">
                        <?php foreach ( $groups[ $current_group ]['tabs'] as $t_id => $t_label ) : ?>
                            <a href="<?php echo esc_url( self::url( $t_id ) ); ?>" class="stk-h-tab<?php echo $tab === $t_id ? ' active' : ''; ?>"><?php echo esc_html( $t_label ); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
                switch ( $tab ) {
                    case 'sitemap':         self::tab_sitemap( $o ); break;
                    case 'sitemap-content': self::tab_sitemap_content( $o ); break;
                    case 'meta':            self::tab_meta( $o ); break;
                    case 'schema':          self::tab_schema( $o ); break;
                    case 'indexing':        self::tab_indexnow( $o ); break;
                    case 'google':          self::tab_google( $o ); break;
                    case 'hydro':           self::tab_hydro( $o ); break;
                    case 'health':          self::tab_health( $o ); break;
                    case 'system':          self::tab_system( $o ); break;
                }
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // -----------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------

    private static function render_dashboard( array $o, array $groups ) {
        $status      = self::status( $o );
        $total_posts = Saiteki_Sitemap::total_posts();
        $files       = $o['enable_sitemap_cleaner'] === '1' ? Saiteki_Sitemap::page_count( $total_posts ) + 1 : 0;
        $health      = self::health( $o );
        $recs        = array_slice( $status['recs'], 0, 3 );

        $modules = array(
            'enable_sitemap_cleaner' => __( 'XML Sitemap', 'saiteki' ),
            'sitemap_videos'         => __( 'Video sitemap extension', 'saiteki' ),
            'enable_schema'          => __( 'JSON-LD Schema', 'saiteki' ),
            'enable_twitter_cards'   => __( 'Twitter Player Cards', 'saiteki' ),
            'enable_dynamic_titles'  => __( 'Dynamic Titles', 'saiteki' ),
            'noindex_tags'           => __( 'Noindex tag archives', 'saiteki' ),
            'enable_api_indexing'    => __( 'Instant Indexing', 'saiteki' ),
            'enable_hydro_bridge'    => __( 'Hydro Bridge', 'saiteki' ),
        );
        $enabled = 0;
        foreach ( $modules as $key => $label ) {
            if ( $o[ $key ] === '1' ) $enabled++;
        }
        ?>
        <div class="stk-dashboard-hero">
            <div class="stk-status-card">
                <div class="stk-status-label"><?php esc_html_e( 'SEO Status', 'saiteki' ); ?></div>
                <div class="stk-status-main">
                    <div class="stk-score <?php echo esc_attr( $status['class'] ); ?>">
                        <strong><?php echo esc_html( (string) $status['score'] ); ?></strong><small><?php esc_html_e( 'score', 'saiteki' ); ?></small>
                    </div>
                    <div>
                        <div class="stk-status-name"><?php echo esc_html( $status['name'] ); ?></div>
                        <div class="stk-status-desc"><?php echo esc_html( $status['desc'] ); ?></div>
                    </div>
                </div>
                <div class="stk-status-meta">
                    <span class="stk-status-chip"><span class="stk-dot" style="background:<?php echo esc_attr( $status['color'] ); ?>"></span><?php echo '1' === (string) get_option( 'blog_public' ) ? esc_html__( 'Visible to search engines', 'saiteki' ) : esc_html__( 'Hidden from search engines', 'saiteki' ); ?></span>
                    <span class="stk-status-chip"><?php echo esc_html( sprintf( /* translators: 1: number of enabled modules, 2: number of all modules */ __( '%1$d / %2$d modules', 'saiteki' ), $enabled, count( $modules ) ) ); ?></span>
                </div>
                <div class="stk-recommendations">
                    <div class="stk-rec-head">
                        <span class="stk-rec-title"><?php esc_html_e( 'Recommended actions', 'saiteki' ); ?></span>
                        <span class="stk-rec-count"><?php echo esc_html( (string) count( $status['recs'] ) ); ?></span>
                    </div>
                    <?php if ( empty( $recs ) ) : ?>
                        <div class="stk-muted"><?php esc_html_e( 'Nothing to do. Everything that matters is switched on.', 'saiteki' ); ?></div>
                    <?php else : ?>
                        <div class="stk-rec-list">
                            <?php foreach ( $recs as $rec ) : ?>
                                <div class="stk-rec <?php echo esc_attr( $rec['level'] ); ?>">
                                    <span class="stk-rec-dot"></span>
                                    <div class="stk-rec-main">
                                        <div class="stk-rec-titleline"><?php echo esc_html( $rec['title'] ); ?>
                                            <span class="stk-rec-badge"><?php echo esc_html( 'important' === $rec['level'] ? __( 'Important', 'saiteki' ) : ( 'optional' === $rec['level'] ? __( 'Optional', 'saiteki' ) : __( 'Recommended', 'saiteki' ) ) ); ?></span>
                                        </div>
                                        <div class="stk-rec-desc"><?php echo esc_html( $rec['desc'] ); ?></div>
                                    </div>
                                    <a class="stk-rec-link" href="<?php echo esc_url( $rec['url'] ); ?>"><?php echo esc_html( $rec['action'] ); ?> ›</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stk-away-card">
                <div class="stk-away-head">
                    <div>
                        <h3><?php esc_html_e( 'Sitemap', 'saiteki' ); ?></h3>
                        <p><?php esc_html_e( 'Virtual files, generated on request. Nothing is written to disk.', 'saiteki' ); ?></p>
                    </div>
                    <a class="button button-secondary stk-mini" href="<?php echo esc_url( self::url( 'sitemap' ) ); ?>"><?php esc_html_e( 'Change path', 'saiteki' ); ?></a>
                </div>
                <?php if ( $o['enable_sitemap_cleaner'] === '1' ) : ?>
                    <?php self::url_row( __( 'Index', 'saiteki' ), Saiteki_Sitemap::index_url( $o ) ); ?>
                    <?php self::url_row( __( 'Pages', 'saiteki' ), Saiteki_Sitemap::pages_url( $o ) ); ?>
                    <?php self::url_row( __( 'Latest posts', 'saiteki' ), Saiteki_Sitemap::chunk_url( Saiteki_Sitemap::page_count( $total_posts ), $o ) ); ?>
                    <div class="stk-actions">
                        <?php self::action_button( 'sitemap_test', __( 'Test reachability', 'saiteki' ), array( 'result' => 'stk-sitemap-result' ) ); ?>
                        <span id="stk-sitemap-result" class="stk-result"></span>
                    </div>
                <?php else : ?>
                    <div class="stk-muted"><?php esc_html_e( 'The sitemap engine is switched off. WordPress serves its own sitemap instead.', 'saiteki' ); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="stk-metric-grid">
            <div class="stk-metric">
                <div class="stk-metric-label"><?php esc_html_e( 'Published videos', 'saiteki' ); ?></div>
                <div class="stk-metric-value"><?php echo esc_html( number_format_i18n( $total_posts ) ); ?></div>
                <div class="stk-metric-note"><?php esc_html_e( 'Posts in the sitemap', 'saiteki' ); ?></div>
            </div>
            <div class="stk-metric">
                <div class="stk-metric-label"><?php esc_html_e( 'Sitemap files', 'saiteki' ); ?></div>
                <div class="stk-metric-value"><?php echo esc_html( number_format_i18n( $files ) ); ?></div>
                <div class="stk-metric-note"><?php echo esc_html( sprintf( /* translators: %s: number of posts per file */ __( '%s posts per file', 'saiteki' ), number_format_i18n( (int) $o['sitemap_limit'] ) ) ); ?></div>
            </div>
            <div class="stk-metric">
                <div class="stk-metric-label"><?php esc_html_e( 'Google keys', 'saiteki' ); ?></div>
                <div class="stk-metric-value"><?php echo esc_html( number_format_i18n( (int) $o['google_key_count'] ) ); ?></div>
                <div class="stk-metric-note"><?php echo (int) $o['google_key_count'] > 1 ? esc_html__( 'Rotation active', 'saiteki' ) : esc_html__( 'Indexing API', 'saiteki' ); ?></div>
            </div>
            <div class="stk-metric">
                <div class="stk-metric-label"><?php esc_html_e( 'IndexNow', 'saiteki' ); ?></div>
                <div class="stk-metric-value"><?php echo ( $o['enable_api_indexing'] === '1' && Saiteki_Indexing::indexnow_key() !== '' ) ? esc_html__( 'On', 'saiteki' ) : esc_html__( 'Off', 'saiteki' ); ?></div>
                <div class="stk-metric-note"><?php esc_html_e( 'Bing, Yandex, Seznam, Naver', 'saiteki' ); ?></div>
            </div>
        </div>

        <div class="stk-cockpit-grid">
            <div class="stk-card">
                <h3><?php esc_html_e( 'Modules', 'saiteki' ); ?></h3>
                <div class="stk-protection-list">
                    <?php foreach ( $modules as $key => $label ) : ?>
                        <div class="stk-protection-item">
                            <span class="stk-protection-name"><?php echo esc_html( $label ); ?></span>
                            <span class="stk-mini-status <?php echo $o[ $key ] === '1' ? 'on' : 'off'; ?>"><?php echo $o[ $key ] === '1' ? esc_html__( 'Active', 'saiteki' ) : esc_html__( 'Off', 'saiteki' ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="stk-card">
                <h3><?php esc_html_e( 'SEO Health Audit', 'saiteki' ); ?></h3>
                <?php if ( null === $health['thumbs'] && null === $health['desc'] ) : ?>
                    <div class="stk-muted"><?php esc_html_e( 'The audit is switched off, so it costs no database queries.', 'saiteki' ); ?></div>
                    <div class="stk-actions"><a class="button button-secondary stk-mini" href="<?php echo esc_url( self::url( 'health' ) ); ?>"><?php esc_html_e( 'Configure', 'saiteki' ); ?></a></div>
                <?php else : ?>
                    <?php self::health_rows( $health ); ?>
                    <div class="stk-actions"><a class="button button-secondary stk-mini" href="<?php echo esc_url( self::url( 'health' ) ); ?>"><?php esc_html_e( 'Details', 'saiteki' ); ?></a></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="stk-section-grid">
            <?php foreach ( $groups as $group ) : ?>
                <a href="<?php echo esc_url( self::url( (string) array_key_first( $group['tabs'] ) ) ); ?>" class="stk-section-card">
                    <div class="stk-section-icon"><span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span></div>
                    <div class="stk-section-info">
                        <h3><?php echo esc_html( $group['title'] ); ?></h3>
                        <p><?php echo esc_html( $group['desc'] ); ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function health_rows( array $health ) {
        if ( null !== $health['thumbs'] ) {
            echo '<div class="stk-kv"><span class="k">' . esc_html__( 'Videos without featured image', 'saiteki' ) . '</span><span class="v"><span class="stk-pill ' . ( $health['thumbs'] > 0 ? 'bad' : 'good' ) . '">' . esc_html( number_format_i18n( (int) $health['thumbs'] ) ) . '</span></span></div>';
        }
        if ( null !== $health['desc'] ) {
            echo '<div class="stk-kv"><span class="k">' . esc_html__( 'Videos with a very short description (< 100 characters)', 'saiteki' ) . '</span><span class="v"><span class="stk-pill ' . ( $health['desc'] > 0 ? 'bad' : 'good' ) . '">' . esc_html( number_format_i18n( (int) $health['desc'] ) ) . '</span></span></div>';
        }
    }

    // -----------------------------------------------------------------
    // Sitemap
    // -----------------------------------------------------------------

    private static function tab_sitemap( array $o ) {
        $total = Saiteki_Sitemap::total_posts();
        $pages = Saiteki_Sitemap::page_count( $total );
        $home  = trailingslashit( home_url( '/' ) );
        $has_prev = $o['sitemap_prev_index'] !== '';
        $prev_url = $has_prev ? home_url( '/' . ( $o['sitemap_prev_dir'] !== '' ? $o['sitemap_prev_dir'] . '/' : '' ) . $o['sitemap_prev_index'] ) : '';
        ?>
        <div class="stk-grid">
            <div class="stk-card">
                <h3><?php esc_html_e( 'Sitemap Engine', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'enable_sitemap_cleaner', __( 'High-performance XML sitemap', 'saiteki' ), __( 'Replaces the WordPress core sitemap. Posts are listed oldest first, so a new post only changes the last file.', 'saiteki' ) ); ?>
                <?php self::toggle( $o, 'sitemap_robots', __( 'Announce in robots.txt', 'saiteki' ), __( 'Adds a "Sitemap:" line with the index URL to the virtual robots.txt.', 'saiteki' ) ); ?>
                <?php if ( file_exists( ABSPATH . 'robots.txt' ) ) : ?>
                    <div class="stk-note stk-note-warn"><?php esc_html_e( 'A physical robots.txt file exists in the site root. WordPress cannot change it: update its "Sitemap:" line by hand after changing the path.', 'saiteki' ); ?></div>
                <?php endif; ?>
            </div>

            <div class="stk-card stk-span-2">
                <h3><?php esc_html_e( 'Custom Path', 'saiteki' ); ?></h3>
                <p class="stk-switch-desc"><?php esc_html_e( 'Choose where the sitemap lives. The path is virtual: no folders or files are created, so it works with renamed upload or content directories as well.', 'saiteki' ); ?></p>
                <form class="stk-form" data-stk-section="sitemap_path">
                    <label class="stk-field-label" for="stk-sitemap-dir"><?php esc_html_e( 'Directory', 'saiteki' ); ?></label>
                    <div class="stk-input-group">
                        <span class="stk-input-prefix"><?php echo esc_html( $home ); ?></span>
                        <input type="text" id="stk-sitemap-dir" name="sitemap_dir" class="stk-form-input" value="<?php echo esc_attr( $o['sitemap_dir'] ); ?>" placeholder="<?php esc_attr_e( '(site root)', 'saiteki' ); ?>" spellcheck="false" autocomplete="off">
                    </div>
                    <span class="stk-switch-desc"><?php esc_html_e( 'Leave empty for the site root. Sub-folders are allowed, for example "media/sitemaps".', 'saiteki' ); ?></span>

                    <div class="stk-field-row">
                        <div>
                            <label class="stk-field-label" for="stk-sitemap-index"><?php esc_html_e( 'Index file name', 'saiteki' ); ?></label>
                            <input type="text" id="stk-sitemap-index" name="sitemap_index" class="stk-form-input" value="<?php echo esc_attr( $o['sitemap_index'] ); ?>" spellcheck="false" autocomplete="off">
                        </div>
                        <div>
                            <label class="stk-field-label" for="stk-sitemap-prefix"><?php esc_html_e( 'Prefix of the sub-sitemaps', 'saiteki' ); ?></label>
                            <input type="text" id="stk-sitemap-prefix" name="sitemap_prefix" class="stk-form-input" value="<?php echo esc_attr( $o['sitemap_prefix'] ); ?>" spellcheck="false" autocomplete="off">
                        </div>
                    </div>

                    <div class="stk-preview" id="stk-sitemap-preview" data-pages="<?php echo esc_attr( (string) $pages ); ?>">
                        <div class="stk-preview-title"><?php esc_html_e( 'Preview', 'saiteki' ); ?></div>
                        <code data-stk-preview="index"></code>
                        <code data-stk-preview="pages"></code>
                        <code data-stk-preview="first"></code>
                        <code data-stk-preview="last"></code>
                    </div>

                    <div class="stk-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save path', 'saiteki' ); ?></button>
                    </div>
                </form>
            </div>

            <div class="stk-card stk-span-2">
                <h3><?php esc_html_e( 'Current Files', 'saiteki' ); ?></h3>
                <?php self::module_off_notice( $o['enable_sitemap_cleaner'] === '1', 'sitemap', __( 'The sitemap engine is switched off, these URLs are not served.', 'saiteki' ) ); ?>
                <?php self::url_row( __( 'Index', 'saiteki' ), Saiteki_Sitemap::index_url( $o ) ); ?>
                <?php self::url_row( __( 'Pages', 'saiteki' ), Saiteki_Sitemap::pages_url( $o ) ); ?>
                <?php self::url_row( __( 'Posts 1', 'saiteki' ), Saiteki_Sitemap::chunk_url( 1, $o ) ); ?>
                <?php if ( $pages > 1 ) : ?>
                    <?php self::url_row( sprintf( /* translators: %d: file number */ __( 'Posts %d', 'saiteki' ), $pages ), Saiteki_Sitemap::chunk_url( $pages, $o ) ); ?>
                <?php endif; ?>
                <div class="stk-mini-text"><?php echo esc_html( sprintf( /* translators: 1: number of posts, 2: number of files */ __( '%1$s published posts in %2$s post files.', 'saiteki' ), number_format_i18n( $total ), number_format_i18n( $pages ) ) ); ?></div>
                <div class="stk-actions">
                    <?php self::action_button( 'sitemap_test', __( 'Test reachability', 'saiteki' ), array( 'result' => 'stk-sitemap-result' ) ); ?>
                    <span id="stk-sitemap-result" class="stk-result"></span>
                </div>
            </div>

            <div class="stk-card">
                <h3><?php esc_html_e( 'Previous Location', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'sitemap_redirect_old', __( 'Redirect the old path (301)', 'saiteki' ), __( 'After a path change, requests to the previous location are forwarded to the new one.', 'saiteki' ) ); ?>
                <?php if ( $has_prev ) : ?>
                    <div class="stk-mini-text"><?php esc_html_e( 'Previous index:', 'saiteki' ); ?><br><code><?php echo esc_html( $prev_url ); ?></code></div>
                    <div class="stk-actions"><?php self::action_button( 'sitemap_forget_old', __( 'Forget previous location', 'saiteki' ), array( 'mini' => true ) ); ?></div>
                <?php else : ?>
                    <div class="stk-muted"><?php esc_html_e( 'The path has not been changed yet.', 'saiteki' ); ?></div>
                <?php endif; ?>
                <div class="stk-note"><?php esc_html_e( 'After moving the sitemap, submit the new index URL in Google Search Console and Bing Webmaster Tools.', 'saiteki' ); ?></div>
            </div>
        </div>
        <?php
    }

    private static function tab_sitemap_content( array $o ) {
        ?>
        <div class="stk-grid">
            <div class="stk-card">
                <h3><?php esc_html_e( 'Media Extensions', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'sitemap_videos', __( 'Video entries', 'saiteki' ), __( 'Adds title, description, thumbnail and player URL for every post with a video.', 'saiteki' ) ); ?>
                <?php self::toggle( $o, 'sitemap_images', __( 'Image entries', 'saiteki' ), __( 'Adds the featured image of every post.', 'saiteki' ) ); ?>
            </div>
            <div class="stk-card">
                <h3><?php esc_html_e( 'Pages File', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'sitemap_pages', __( 'Static pages', 'saiteki' ), __( 'Published pages without a password.', 'saiteki' ) ); ?>
                <?php self::toggle( $o, 'sitemap_categories', __( 'Categories', 'saiteki' ), __( 'Category archives that contain at least one post.', 'saiteki' ) ); ?>
                <div class="stk-mini-text"><?php esc_html_e( 'The home page is always listed. Tag archives are left out.', 'saiteki' ); ?></div>
            </div>
            <div class="stk-card">
                <h3><?php esc_html_e( 'File Size', 'saiteki' ); ?></h3>
                <form class="stk-form" data-stk-section="sitemap_content">
                    <label class="stk-field-label" for="stk-sitemap-limit"><?php esc_html_e( 'Posts per file', 'saiteki' ); ?></label>
                    <input type="number" id="stk-sitemap-limit" name="sitemap_limit" class="stk-form-input" min="100" max="20000" step="100" value="<?php echo esc_attr( (string) (int) $o['sitemap_limit'] ); ?>">
                    <span class="stk-switch-desc"><?php esc_html_e( '100 to 20,000. Changing this value moves posts between files, so search engines fetch every file again. 5,000 is a good balance.', 'saiteki' ); ?></span>
                    <div class="stk-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'saiteki' ); ?></button></div>
                </form>
            </div>
            <div class="stk-card">
                <h3><?php esc_html_e( 'Video Source', 'saiteki' ); ?></h3>
                <div class="stk-kv"><span class="k"><?php esc_html_e( 'Theme helper jpop_get_turbo_video()', 'saiteki' ); ?></span><span class="v"><span class="stk-pill <?php echo function_exists( 'jpop_get_turbo_video' ) ? 'good' : ''; ?>"><?php echo function_exists( 'jpop_get_turbo_video' ) ? esc_html__( 'Found', 'saiteki' ) : esc_html__( 'Not found', 'saiteki' ); ?></span></span></div>
                <div class="stk-mini-text"><?php esc_html_e( 'Without the helper, the first YouTube link in the post content is used. Other sources can be connected with the "saiteki_video_data" filter.', 'saiteki' ); ?></div>
            </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------
    // Frontend
    // -----------------------------------------------------------------

    private static function tab_meta( array $o ) {
        ?>
        <div class="stk-grid">
            <div class="stk-card">
                <h3><?php esc_html_e( 'Social Tags', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'enable_twitter_cards', __( 'Twitter player cards', 'saiteki' ), __( 'Video posts are shared with an inline player. When off, a large image card is used.', 'saiteki' ) ); ?>
                <div class="stk-mini-text"><?php esc_html_e( 'Meta description and Open Graph tags are always printed for posts, pages, the home page, categories and tags.', 'saiteki' ); ?></div>
            </div>
            <div class="stk-card">
                <h3><?php esc_html_e( 'Crawling', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'noindex_tags', __( 'Noindex tag archives', 'saiteki' ), __( 'Tag archives get "noindex, follow", so the crawl budget goes to the videos.', 'saiteki' ) ); ?>
            </div>
            <?php $conflicts = self::conflicts(); ?>
            <?php if ( ! empty( $conflicts ) ) : ?>
                <div class="stk-card">
                    <h3><?php esc_html_e( 'Conflicts', 'saiteki' ); ?></h3>
                    <div class="stk-note stk-note-warn"><?php echo esc_html( sprintf( /* translators: %s: plugin names */ __( '%s prints the same tags. Disable the overlapping features to avoid duplicates.', 'saiteki' ), implode( ', ', $conflicts ) ) ); ?></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function tab_schema( array $o ) {
        ?>
        <div class="stk-grid">
            <div class="stk-card">
                <h3><?php esc_html_e( 'Structured Data', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'enable_schema', __( 'JSON-LD schema', 'saiteki' ), __( 'VideoObject and BreadcrumbList on posts, ItemList on the home page and on categories.', 'saiteki' ) ); ?>
            </div>
            <div class="stk-card">
                <h3><?php esc_html_e( 'Titles', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'enable_dynamic_titles', __( 'Dynamic titles', 'saiteki' ), __( 'Appends a suffix to the browser title of single posts.', 'saiteki' ) ); ?>
                <form class="stk-form" data-stk-section="titles">
                    <label class="stk-field-label" for="stk-title-suffix"><?php esc_html_e( 'Title suffix', 'saiteki' ); ?></label>
                    <input type="text" id="stk-title-suffix" name="title_suffix" class="stk-form-input" maxlength="80" value="<?php echo esc_attr( $o['title_suffix'] ); ?>">
                    <span class="stk-switch-desc"><?php esc_html_e( '{year} is replaced by the current year. Example: "Video {year}".', 'saiteki' ); ?></span>
                    <div class="stk-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'saiteki' ); ?></button></div>
                </form>
            </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------
    // Indexing
    // -----------------------------------------------------------------

    private static function tab_indexnow( array $o ) {
        $key = Saiteki_Indexing::indexnow_key();
        ?>
        <div class="stk-grid">
            <div class="stk-card">
                <h3><?php esc_html_e( 'Instant Indexing', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'enable_api_indexing', __( 'Report new posts', 'saiteki' ), __( 'Master switch for IndexNow and the Google Indexing API. A post is reported once, when it is published. IndexNow receives all posts of one request or import run in a single batch.', 'saiteki' ) ); ?>
            </div>
            <div class="stk-card stk-span-2">
                <h3><?php esc_html_e( 'IndexNow', 'saiteki' ); ?></h3>
                <?php if ( $key === '' ) : ?>
                    <div class="stk-note stk-note-warn"><?php esc_html_e( 'No readable key is stored. Generate a new one.', 'saiteki' ); ?></div>
                <?php else : ?>
                    <div class="stk-kv"><span class="k"><?php esc_html_e( 'Key', 'saiteki' ); ?></span><span class="v"><code><?php echo esc_html( $key ); ?></code></span></div>
                    <?php self::url_row( __( 'Key file', 'saiteki' ), Saiteki_Indexing::indexnow_key_url() ); ?>
                    <div class="stk-mini-text"><?php esc_html_e( 'The key file is virtual and proves to IndexNow that this site sent the request. It is public by design.', 'saiteki' ); ?></div>
                <?php endif; ?>
                <div class="stk-actions">
                    <?php self::action_button( 'indexnow_test', __( 'Send test request', 'saiteki' ), array( 'result' => 'stk-indexnow-result' ) ); ?>
                    <?php self::action_button( 'indexnow_regen', __( 'Generate new key', 'saiteki' ), array( 'confirm' => __( 'Generate a new IndexNow key? The old key file stops working immediately.', 'saiteki' ) ) ); ?>
                    <span id="stk-indexnow-result" class="stk-result"></span>
                </div>
            </div>
        </div>
        <?php
    }

    private static function tab_google( array $o ) {
        $keys       = Saiteki_Indexing::get_google_keys();
        $unreadable = Saiteki_Indexing::google_keys_unreadable();
        $total      = count( $keys );
        $pointer    = $total > 0 ? ( (int) saiteki_get_setting( 'saiteki_google_key_index', 0 ) ) % $total : 0;
        ?>
        <div class="stk-grid">
            <div class="stk-card stk-span-2">
                <h3><?php esc_html_e( 'Stored Keys', 'saiteki' ); ?></h3>
                <?php self::module_off_notice( $o['enable_api_indexing'] === '1', 'indexing', __( 'Instant indexing is switched off, the keys are not used.', 'saiteki' ) ); ?>
                <?php if ( $unreadable ) : ?>
                    <div class="stk-note stk-note-warn"><?php esc_html_e( 'Keys are stored but cannot be decrypted: the wp-config.php salts have changed since they were saved. Paste the service account JSON files again.', 'saiteki' ); ?></div>
                <?php elseif ( 0 === $total ) : ?>
                    <div class="stk-muted"><?php esc_html_e( 'No keys stored yet. The Google Indexing API is not used.', 'saiteki' ); ?></div>
                <?php else : ?>
                    <table class="stk-table">
                        <thead><tr><th>#</th><th><?php esc_html_e( 'Service account', 'saiteki' ); ?></th><th><?php esc_html_e( 'Project', 'saiteki' ); ?></th><th><?php esc_html_e( 'Rotation', 'saiteki' ); ?></th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ( $keys as $i => $sa ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
                                <td><code><?php echo esc_html( (string) ( $sa['client_email'] ?? '?' ) ); ?></code></td>
                                <td><?php echo esc_html( (string) ( $sa['project_id'] ?? '' ) ); ?></td>
                                <td><?php if ( $i === $pointer && $total > 1 ) : ?><span class="stk-pill good"><?php esc_html_e( 'Next', 'saiteki' ); ?></span><?php endif; ?></td>
                                <td class="stk-table-actions">
                                    <?php self::action_button( 'google_test', __( 'Test', 'saiteki' ), array( 'mini' => true, 'pos' => $i, 'result' => 'stk-google-result' ) ); ?>
                                    <?php self::action_button( 'google_remove', __( 'Remove', 'saiteki' ), array( 'mini' => true, 'danger' => true, 'pos' => $i, 'confirm' => __( 'Remove this key?', 'saiteki' ) ) ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="stk-actions">
                        <span id="stk-google-result" class="stk-result"></span>
                        <?php self::action_button( 'google_clear', __( 'Remove all keys', 'saiteki' ), array( 'mini' => true, 'danger' => true, 'confirm' => __( 'Remove all Google keys?', 'saiteki' ) ) ); ?>
                    </div>
                    <div class="stk-mini-text"><?php esc_html_e( 'Rotation: every published post uses the next key in the list (round robin), so the daily quota of each key is spread evenly. The private keys are stored encrypted (AES-256-GCM) and are never shown again.', 'saiteki' ); ?></div>
                <?php endif; ?>
            </div>

            <div class="stk-card stk-span-2">
                <h3><?php esc_html_e( 'Add Keys', 'saiteki' ); ?></h3>
                <form class="stk-form" data-stk-section="google_keys">
                    <p class="stk-switch-desc"><?php esc_html_e( 'Paste the contents of one or more service account JSON files, simply one after the other.', 'saiteki' ); ?></p>
                    <textarea name="google_keys" class="stk-form-input" rows="6" spellcheck="false" autocomplete="off" placeholder="<?php esc_attr_e( 'Paste multiple JSONs here one below the other...', 'saiteki' ); ?>"></textarea>
                    <div class="stk-radio-row">
                        <label><input type="radio" name="google_mode" value="replace" <?php checked( 0 === $total ); ?>> <?php esc_html_e( 'Replace the stored keys (rotation starts again at key 1)', 'saiteki' ); ?></label>
                        <label><input type="radio" name="google_mode" value="add" <?php checked( $total > 0 ); ?>> <?php esc_html_e( 'Add to the stored keys', 'saiteki' ); ?></label>
                    </div>
                    <div class="stk-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save keys', 'saiteki' ); ?></button></div>
                </form>
            </div>
        </div>
        <?php
    }

    private static function tab_hydro( array $o ) {
        $active = function_exists( 'hydro_create_or_get_shortlink' );
        ?>
        <div class="stk-grid">
            <div class="stk-card">
                <h3><?php esc_html_e( 'Hydro Shortlinks', 'saiteki' ); ?></h3>
                <div class="stk-kv"><span class="k"><?php esc_html_e( 'Hydro Shortlinks plugin', 'saiteki' ); ?></span><span class="v"><span class="stk-pill <?php echo $active ? 'good' : ''; ?>"><?php echo $active ? esc_html__( 'Connected', 'saiteki' ) : esc_html__( 'Not active', 'saiteki' ); ?></span></span></div>
                <?php self::toggle( $o, 'enable_hydro_bridge', __( 'Hydro bridge', 'saiteki' ), __( 'Uses the Hydro short URL as rel="shortlink" of every post. The setting is kept while Hydro is inactive.', 'saiteki' ) ); ?>
                <div class="stk-mini-text"><?php esc_html_e( 'The short URL is looked up once per post and remembered, so Hydro\'s link table is not scanned on every page view. og:url always stays the canonical URL.', 'saiteki' ); ?></div>
            </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------
    // Tools
    // -----------------------------------------------------------------

    private static function tab_health( array $o ) {
        $health = self::health( $o );
        ?>
        <div class="stk-grid">
            <div class="stk-card">
                <h3><?php esc_html_e( 'Checks', 'saiteki' ); ?></h3>
                <?php self::toggle( $o, 'enable_health_thumbs', __( 'Missing thumbnails', 'saiteki' ), __( 'Counts published videos without their own featured image.', 'saiteki' ) ); ?>
                <?php self::toggle( $o, 'enable_health_desc', __( 'Short descriptions', 'saiteki' ), __( 'Counts published posts with less than 100 characters of content and excerpt.', 'saiteki' ) ); ?>
                <div class="stk-mini-text"><?php esc_html_e( 'Both checks scan the posts table. The result is cached for 15 minutes; switched off, they cost nothing.', 'saiteki' ); ?></div>
            </div>
            <div class="stk-card">
                <h3><?php esc_html_e( 'Result', 'saiteki' ); ?></h3>
                <?php if ( null === $health['thumbs'] && null === $health['desc'] ) : ?>
                    <div class="stk-muted"><?php esc_html_e( 'Switch on a check and reload the page to see the result.', 'saiteki' ); ?></div>
                <?php else : ?>
                    <?php self::health_rows( $health ); ?>
                    <div class="stk-mini-text"><?php echo esc_html( sprintf( /* translators: %s: time span, e.g. "5 mins" */ __( 'Counted %s ago.', 'saiteki' ), human_time_diff( (int) $health['time'], time() ) ) ); ?></div>
                <?php endif; ?>
                <div class="stk-actions"><?php self::action_button( 'health_refresh', __( 'Count again', 'saiteki' ), array( 'mini' => true ) ); ?></div>
            </div>
        </div>
        <?php
    }

    private static function tab_system( array $o ) {
        global $wpdb;
        $mirror = get_option( 'saiteki_settings_mirror', array() );
        $cold   = saiteki_get_setting( 'saiteki_google_keys', '' );
        $rows   = array(
            __( 'Saiteki SEO', 'saiteki' )             => SAITEKI_VERSION,
            __( 'WordPress', 'saiteki' )               => get_bloginfo( 'version' ),
            __( 'PHP', 'saiteki' )                     => PHP_VERSION,
            __( 'Settings table', 'saiteki' )          => $wpdb->prefix . 'saiteki_settings' . ( saiteki_table_ready() ? '' : ' (' . __( 'missing', 'saiteki' ) . ')' ),
            __( 'Autoloaded settings', 'saiteki' )     => size_format( strlen( (string) maybe_serialize( $mirror ) ) ) ?: '0 B',
            __( 'Encrypted Google keys (not autoloaded)', 'saiteki' ) => is_string( $cold ) && $cold !== '' ? size_format( strlen( $cold ) ) : '-',
            __( 'Encryption', 'saiteki' )              => in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ? 'AES-256-GCM' : __( 'not available', 'saiteki' ),
            __( 'Persistent object cache', 'saiteki' ) => wp_using_ext_object_cache() ? __( 'Yes', 'saiteki' ) : __( 'No', 'saiteki' ),
            __( 'Sitemap index cache', 'saiteki' )     => is_array( get_transient( Saiteki_Sitemap::INDEX_CACHE ) ) ? __( 'Built', 'saiteki' ) : __( 'Empty (built on the next request)', 'saiteki' ),
            __( 'Permalink structure', 'saiteki' )     => (string) get_option( 'permalink_structure' ) ?: __( 'Plain', 'saiteki' ),
        );
        ?>
        <div class="stk-grid">
            <div class="stk-card stk-span-2">
                <h3><?php esc_html_e( 'System', 'saiteki' ); ?></h3>
                <table class="stk-table">
                    <tbody>
                    <?php foreach ( $rows as $label => $value ) : ?>
                        <tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="stk-card">
                <h3><?php esc_html_e( 'Maintenance', 'saiteki' ); ?></h3>
                <div class="stk-mini-text"><?php esc_html_e( 'The sitemap index (file list with last-modified dates) is cached until content changes, at most 12 hours. Rebuild it after a bulk import that bypasses WordPress.', 'saiteki' ); ?></div>
                <div class="stk-actions"><?php self::action_button( 'sitemap_flush', __( 'Rebuild sitemap index', 'saiteki' ), array( 'mini' => true ) ); ?></div>
            </div>
        </div>
        <?php
    }
}
