<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Saiteki_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'load_assets' ) );
    }

    public static function register_menu() {
        add_menu_page( __( 'Saiteki SEO', 'saiteki' ), __( 'Saiteki', 'saiteki' ), 'manage_options', 'saiteki-dashboard', array( __CLASS__, 'render_dashboard' ), 'dashicons-superhero-alt', 3 );
    }

    public static function load_assets( $hook ) {
        if ( $hook !== 'toplevel_page_saiteki-dashboard' ) return;
        wp_enqueue_style( 'saiteki-hydro-css', SAITEKI_URL . 'css/admin.css', array(), SAITEKI_VERSION );
        
        wp_add_inline_style( 'saiteki-hydro-css', '
            .saiteki-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
            .saiteki-switch input { opacity: 0; width: 0; height: 0; }
            .saiteki-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--border); transition: .3s; border-radius: 24px; }
            .saiteki-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
            input:checked + .saiteki-slider { background-color: var(--primary); }
            input:checked + .saiteki-slider:before { transform: translateX(20px); }
            .saiteki-setting-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border); }
            .saiteki-setting-row:last-child { border-bottom: none; }
            .saiteki-setting-desc h4 { margin: 0 0 4px 0; font-size: 15px; }
            .saiteki-setting-desc p { margin: 0; font-size: 13px; color: var(--muted); }
            .hydro-badge { background: linear-gradient(90deg, #06b6d4, #7c3aed); color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; vertical-align: middle; margin-left: 6px; font-weight: bold; }
            .saiteki-tab-content { display: none; animation: hydroFade .22s ease-out; }
            .saiteki-tab-content.active { display: block; }
            .saiteki-api-box { padding: 15px 0; border-top: 1px solid var(--border); margin-top: 15px; }
            .saiteki-textarea, .saiteki-input { width: 100%; font-family: monospace; font-size: 13px; padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: inherit; box-sizing: border-box; margin-top: 8px; display: block; }
            .saiteki-textarea { min-height: 45px; height: auto; resize: vertical; }
            .saiteki-textarea:focus, .saiteki-input:focus { border-color: var(--primary); outline: none; }
            .health-stat { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed var(--border); }
            .health-stat:last-child { border-bottom: none; }
        ');
        
        wp_add_inline_script( 'jquery', '
            document.addEventListener("DOMContentLoaded", function() {
                const tabs = document.querySelectorAll(".hydro-tab");
                const contents = document.querySelectorAll(".saiteki-tab-content");
                let savedTab = localStorage.getItem("saiteki_active_tab") || "tab-dashboard";
                
                function activateTab(tabId) {
                    tabs.forEach(t => t.classList.remove("active"));
                    contents.forEach(c => c.classList.remove("active"));
                    const activeBtn = document.querySelector(`[data-target="${tabId}"]`);
                    const activeContent = document.getElementById(tabId);
                    if(activeBtn && activeContent) {
                        activeBtn.classList.add("active");
                        activeContent.classList.add("active");
                        localStorage.setItem("saiteki_active_tab", tabId);
                    }
                }
                activateTab(savedTab);
                
                tabs.forEach(tab => {
                    tab.addEventListener("click", function(e) {
                        e.preventDefault();
                        activateTab(this.dataset.target);
                    });
                });
            });
        ');
    }

    public static function render_dashboard() {
        $old_options = Saiteki_Core::get_options();

        if ( isset( $_POST['saiteki_save_settings'] ) && check_admin_referer( 'saiteki_nonce_action', 'saiteki_nonce' ) ) {
            $settings = isset($_POST['saiteki_settings']) ? wp_unslash($_POST['saiteki_settings']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            
            $in_json = isset($settings['google_json_key']) ? wp_unslash($settings['google_json_key']) : '';
            if ( strpos($in_json, '--- ENCRYPTED KEY') !== false || empty(trim($in_json)) ) {
                $final_json_key = $old_options['google_json_key'];
            } else {
                // Magic Parser: Erlaubt das einfache Hintereinanderkopieren von mehreren JSONs
                $fixed_json = '[' . preg_replace('/\s*}\s*\{\s*/', '},{', trim($in_json, "[] \t\n\r\0\x0B")) . ']';
                $parsed = json_decode($fixed_json, true);
                
                if (is_array($parsed) && count($parsed) > 0 && isset($parsed[0]['private_key'])) {
                    $final_json_key = Saiteki_Crypto::encrypt(wp_json_encode($parsed));
                    update_option('saiteki_google_key_count', count($parsed));
                    update_option('saiteki_google_key_index', 0); // Rotation zurücksetzen
                } else {
                    $final_json_key = Saiteki_Crypto::encrypt($in_json);
                    update_option('saiteki_google_key_count', 1);
                }
            }

            $new_settings = array(
                'enable_sitemap_cleaner' => isset($settings['enable_sitemap_cleaner']) ? '1' : '0',
                'enable_api_indexing'    => isset($settings['enable_api_indexing']) ? '1' : '0',
                'enable_dynamic_titles'  => isset($settings['enable_dynamic_titles']) ? '1' : '0',
                'enable_twitter_cards'   => isset($settings['enable_twitter_cards']) ? '1' : '0',
                'enable_schema'          => isset($settings['enable_schema']) ? '1' : '0',
                'enable_hydro_bridge'    => isset($settings['enable_hydro_bridge']) ? '1' : '0',
                'enable_health_thumbs'   => isset($settings['enable_health_thumbs']) ? '1' : '0',
                'enable_health_desc'     => isset($settings['enable_health_desc']) ? '1' : '0',
                'indexnow_key'           => $old_options['indexnow_key'], 
                'google_json_key'        => $final_json_key,
            );
            update_option( 'saiteki_settings', $new_settings );
            $old_options = $new_settings;
            echo '<div class="notice notice-success is-dismissible" style="margin-left:0; margin-bottom: 20px;"><p>✅ <strong>Saiteki SEO:</strong> ' . esc_html__( 'Settings successfully saved!', 'saiteki' ) . '</p></div>';
        }

        $hydro_active = function_exists('hydro_create_or_get_shortlink');
        $decrypted_indexnow = Saiteki_Crypto::decrypt($old_options['indexnow_key']);
        
        // --- Health Audit Abfragen (nur wenn <?php esc_html_e( 'active', 'saiteki' ); ?>iert) ---
        global $wpdb;
        $health_thumbs_count = false;
        $health_desc_count = false;
        
        if ( $old_options['enable_health_thumbs'] === '1' ) {
            // Zählt veröffentlichte Posts ohne Thumbnail
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $health_thumbs_count = $wpdb->get_var("SELECT COUNT(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id') WHERE p.post_type = 'post' AND p.post_status = 'publish' AND pm.meta_id IS NULL");
        }
        
        if ( $old_options['enable_health_desc'] === '1' ) {
            // Zählt veröffentlichte Posts mit sehr kurzem Inhalt/Excerpt
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $health_desc_count = $wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND LENGTH(post_content) < 100 AND LENGTH(post_excerpt) < 100");
        }
        ?>
        <div class="hydro-admin-wrap">
            <div class="hydro-app" style="border-radius: 12px; padding: 24px;">
                
                <div class="hydro-top-row" style="margin-bottom: 20px;">
                    <div>
                        <h1 style="margin:0; font-size:24px; font-weight:800; display:flex; align-items:center; gap:10px;">
                            <span style="color:var(--primary);">最適</span> Saiteki SEO
                        </h1>
                    </div>
                    <div>
                        <span style="background: linear-gradient(90deg, #10b981, #059669); color:#fff; padding:4px 12px; border-radius:20px; font-weight:bold; font-size:12px;">v<?php echo esc_html( SAITEKI_VERSION ); ?></span>
                    </div>
                </div>

                <div class="hydro-tabs" style="margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">
                    <button type="button" class="hydro-tab" data-target="tab-dashboard">Dashboard</button>
                    <button type="button" class="hydro-tab" data-target="tab-core">Core Modules</button>
                    <button type="button" class="hydro-tab" data-target="tab-frontend">Frontend SEO</button>
                    <button type="button" class="hydro-tab" data-target="tab-integrations"><?php esc_html_e( 'APIs & Indexing', 'saiteki' ); ?></button>
                </div>

                <form method="post" action="">
                    <?php wp_nonce_field( 'saiteki_nonce_action', 'saiteki_nonce' ); ?>
                    
                    <div id="tab-dashboard" class="saiteki-tab-content active">
                        <div class="hydro-analytics-cards" style="margin-bottom: 20px;">
                            <div class="hydro-analytics-card" style="border-left: 4px solid var(--primary);">
                                <h4>Sitemap Engine</h4>
                                <div class="big-num">Media</div>
                                <div class="small-muted">Videos & Bilder in SQL injiziert.</div>
                            </div>
                            <div class="hydro-analytics-card" style="border-left: 4px solid #10b981;">
                                <h4>Security Status</h4>
                                <div class="big-num">AES-256</div>
                                <div class="small-muted">API Keys in DB verschlüsselt.</div>
                            </div>
                        </div>

                        <?php if ( $old_options['enable_health_thumbs'] === '1' || $old_options['enable_health_desc'] === '1' ) : ?>
                        <div style="background:var(--surface); border:1px solid var(--border); padding: 20px; border-radius:12px; margin-bottom: 20px;">
                            <h3 style="margin-top:0; border-bottom: 1px solid var(--border); padding-bottom: 10px;"><?php esc_html_e( '🩺 SEO Health Audit', 'saiteki' ); ?></h3>
                            
                            <?php if ( $old_options['enable_health_thumbs'] === '1' ) : ?>
                            <div class="health-stat">
                                <span><strong>Videos without featured image (Thumbnail):</strong></span>
                                <span style="color: <?php echo ($health_thumbs_count > 0) ? '#ef4444' : '#10b981'; ?>; font-weight: bold;">
                                    <?php echo intval($health_thumbs_count); ?>
                                </span>
                            </div>
                            <?php endif; ?>

                            <?php if ( $old_options['enable_health_desc'] === '1' ) : ?>
                            <div class="health-stat">
                                <span><strong>Videos with extremely short description (< 100 characters):</strong></span>
                                <span style="color: <?php echo ($health_desc_count > 0) ? '#ef4444' : '#10b981'; ?>; font-weight: bold;">
                                    <?php echo intval($health_desc_count); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <p style="font-size: 12px; color: var(--muted); margin-top: 15px; margin-bottom: 0;">
                                <em>Note: If you no longer need these checks, de<?php esc_html_e( 'active', 'saiteki' ); ?>iere sie im "Core Modules" Tab, um die Datenbankabfragen komplett zu stoppen.</em>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="tab-core" class="saiteki-tab-content">
                        <div style="background:var(--surface); border:1px solid var(--border); padding: 20px; border-radius:12px; margin-bottom: 20px;">
                            <h3 style="margin-top:0; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">Background Processes</h3>
                            
                            <div class="saiteki-setting-row">
                                <div class="saiteki-setting-desc">
                                    <h4>High-Performance Sitemap Engine</h4>
                                </div>
                                <label class="saiteki-switch">
                                    <input type="checkbox" name="saiteki_settings[enable_sitemap_cleaner]" value="1" <?php checked( $old_options['enable_sitemap_cleaner'], '1' ); ?>>
                                    <span class="saiteki-slider"></span>
                                </label>
                            </div>

                            <div class="saiteki-setting-row">
                                <div class="saiteki-setting-desc">
                                    <h4><?php esc_html_e( 'SEO Audit: Missing Thumbnails', 'saiteki' ); ?> <span class="hydro-badge" style="background:#8b5cf6;"><?php esc_html_e( 'Optional', 'saiteki' ); ?></span></h4>
                                    <p><?php esc_html_e( 'Checks the database for videos without their own featured image and shows the result in the dashboard.', 'saiteki' ); ?></p>
                                </div>
                                <label class="saiteki-switch">
                                    <input type="checkbox" name="saiteki_settings[enable_health_thumbs]" value="1" <?php checked( $old_options['enable_health_thumbs'], '1' ); ?>>
                                    <span class="saiteki-slider"></span>
                                </label>
                            </div>

                            <div class="saiteki-setting-row" style="border-bottom: none;">
                                <div class="saiteki-setting-desc">
                                    <h4><?php esc_html_e( 'SEO Audit: Short Descriptions', 'saiteki' ); ?> <span class="hydro-badge" style="background:#8b5cf6;"><?php esc_html_e( 'Optional', 'saiteki' ); ?></span></h4>
                                    <p><?php esc_html_e( 'Checks the database for posts with less than 100 characters of text content.', 'saiteki' ); ?></p>
                                </div>
                                <label class="saiteki-switch">
                                    <input type="checkbox" name="saiteki_settings[enable_health_desc]" value="1" <?php checked( $old_options['enable_health_desc'], '1' ); ?>>
                                    <span class="saiteki-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div style="text-align: right;"><button type="submit" name="saiteki_save_settings" class="button button-primary" style="background:var(--primary); border:none; padding: 6px 24px; border-radius: 6px; font-weight: bold;"><?php esc_html_e( 'Save Settings', 'saiteki' ); ?></button></div>
                    </div>

                    <div id="tab-frontend" class="saiteki-tab-content">
                        <div style="background:var(--surface); border:1px solid var(--border); padding: 20px; border-radius:12px; margin-bottom: 20px;">
                            <h3 style="margin-top:0; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 10px;"><?php esc_html_e( 'Frontend Tags', 'saiteki' ); ?></h3>
                            <div class="saiteki-setting-row"><div class="saiteki-setting-desc"><h4>JSON-LD Schema</h4></div><label class="saiteki-switch"><input type="checkbox" name="saiteki_settings[enable_schema]" value="1" <?php checked( $old_options['enable_schema'], '1' ); ?>><span class="saiteki-slider"></span></label></div>
                            <div class="saiteki-setting-row"><div class="saiteki-setting-desc"><h4>Twitter Cards</h4></div><label class="saiteki-switch"><input type="checkbox" name="saiteki_settings[enable_twitter_cards]" value="1" <?php checked( $old_options['enable_twitter_cards'], '1' ); ?>><span class="saiteki-slider"></span></label></div>
                            <div class="saiteki-setting-row" style="border-bottom:none;"><div class="saiteki-setting-desc"><h4><?php esc_html_e( 'Dynamic Titles', 'saiteki' ); ?></h4></div><label class="saiteki-switch"><input type="checkbox" name="saiteki_settings[enable_dynamic_titles]" value="1" <?php checked( $old_options['enable_dynamic_titles'], '1' ); ?>><span class="saiteki-slider"></span></label></div>
                        </div>
                        <div style="text-align: right;"><button type="submit" name="saiteki_save_settings" class="button button-primary" style="background:var(--primary); border:none; padding: 6px 24px; border-radius: 6px; font-weight: bold;"><?php esc_html_e( 'Save Settings', 'saiteki' ); ?></button></div>
                    </div>

                    <div id="tab-integrations" class="saiteki-tab-content">
                        <div style="background:var(--surface); border:1px solid var(--border); padding: 20px; border-radius:12px; margin-bottom: 20px;">
                            <h3 style="margin-top:0; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 10px;"><?php esc_html_e( 'APIs & Indexing', 'saiteki' ); ?></h3>
                            
                            <div class="saiteki-setting-row">
                                <div class="saiteki-setting-desc">
                                    <h4>Hydro Shortlinks Bridge <?php if($hydro_active) echo '<span class="hydro-badge">' . esc_html__( 'Connected', 'saiteki' ) . '</span>'; ?></h4>
                                </div>
                                <label class="saiteki-switch">
                                    <input type="checkbox" name="saiteki_settings[enable_hydro_bridge]" value="1" <?php checked( $old_options['enable_hydro_bridge'], '1' ); ?> <?php if(!$hydro_active) echo 'disabled'; ?>>
                                    <span class="saiteki-slider"></span>
                                </label>
                            </div>

                            <div class="saiteki-setting-row" style="border-bottom: none;">
                                <div class="saiteki-setting-desc"><h4>Instant Indexing</h4></div>
                                <label class="saiteki-switch">
                                    <input type="checkbox" name="saiteki_settings[enable_api_indexing]" value="1" <?php checked( $old_options['enable_api_indexing'], '1' ); ?>>
                                    <span class="saiteki-slider"></span>
                                </label>
                            </div>

                            <div class="saiteki-api-box">
                                <label style="display:block; font-weight:bold;">IndexNow Key</label>
                                <input type="password" class="saiteki-input" value="<?php echo esc_attr($decrypted_indexnow); ?>" readonly>
                            </div>
                            
                            <div class="saiteki-api-box" style="border-bottom: none;">
                                <label style="display:block; font-weight:bold;">Google Indexing JSON(s) <span class="hydro-badge" style="background:#10b981;"><?php echo esc_html( get_option('saiteki_google_key_count', 1) ); ?> <?php esc_html_e( 'active', 'saiteki' ); ?></span></label>
                                <p style="font-size:12px; color:var(--muted); margin:4px 0 8px;"><?php echo wp_kses_post( __( 'For <b>Multi-Key Rotation</b> (limit bypass), you can simply copy the contents of any number of JSON files directly one after the other into this field.', 'saiteki' ) ); ?></p>
                                <textarea name="saiteki_settings[google_json_key]" class="saiteki-textarea" rows="3" placeholder="<?php esc_attr_e( 'Paste multiple JSONs here one below the other...', 'saiteki' ); ?>"><?php echo !empty($old_options['google_json_key']) ? '--- ENCRYPTED KEYS SAVED ---' : ''; ?></textarea>
                            </div>
                        </div>
                        <div style="text-align: right;"><button type="submit" name="saiteki_save_settings" class="button button-primary" style="background:var(--primary); border:none; padding: 6px 24px; border-radius: 6px; font-weight: bold;"><?php esc_html_e( 'Save Settings', 'saiteki' ); ?></button></div>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
