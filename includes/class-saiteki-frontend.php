<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Saiteki_Frontend {
    private static $options = array();

    public static function init( $options ) {
        self::$options = $options;
        add_action( 'wp_head', array( __CLASS__, 'render_seo_tags' ), 1 );
        remove_action( 'wp_head', 'rel_canonical' );
        
        if ( self::$options['enable_dynamic_titles'] === '1' ) {
            add_filter( 'document_title_parts', array( __CLASS__, 'dynamic_title' ) );
        }
    }

    public static function dynamic_title( $title_parts ) {
        if ( is_singular( 'post' ) ) {
            $title_parts['tagline'] = 'Video ' . gmdate('Y');
        }
        return $title_parts;
    }

    private static function get_latest_grid_image() {
        global $wp_query;
        if ( ! empty( $wp_query->posts ) ) {
            $first_post = $wp_query->posts[0];
            $img = get_the_post_thumbnail_url( $first_post->ID, 'full' );
            if ( $img ) return $img;

            $turbo = function_exists('jpop_get_turbo_video') ? jpop_get_turbo_video($first_post->ID) : false;
            if ( $turbo && !empty($turbo['video_id']) ) {
                return 'https://img.youtube.com/vi/' . $turbo['video_id'] . '/maxresdefault.jpg';
            }
        }
        return '';
    }

    public static function render_seo_tags() {
        echo PHP_EOL . "" . PHP_EOL;

        if ( is_singular( 'post' ) ) {
            global $post;
            $title = esc_attr( get_the_title( $post->ID ) );
            $canonical_url = esc_url( get_permalink( $post->ID ) );
            $url = $canonical_url;
            $excerpt = has_excerpt($post->ID) ? wp_strip_all_tags(get_the_excerpt($post->ID)) : /* translators: %s: Video title */ sprintf( __( 'Watch %s on vTubes.tokyo.', 'saiteki' ), $title );
            
            echo '<link rel="canonical" href="' . esc_url( $canonical_url ) . '">' . PHP_EOL;

            if ( self::$options['enable_hydro_bridge'] === '1' && function_exists( 'hydro_create_or_get_shortlink' ) ) {
                $hydro_url = hydro_create_or_get_shortlink( $canonical_url );
                if ( $hydro_url ) $url = esc_url( $hydro_url ); 
            }
            
            $image_url = get_the_post_thumbnail_url( $post->ID, 'full' );
            $turbo_data = function_exists('jpop_get_turbo_video') ? jpop_get_turbo_video($post->ID) : false;
            $video_id = ($turbo_data && !empty($turbo_data['video_id'])) ? $turbo_data['video_id'] : '';
            
            $raw_meta = $turbo_data ? $turbo_data['raw_meta'] : array();
            $views = isset($raw_meta['view_count'][0]) ? (int)$raw_meta['view_count'][0] : (isset($raw_meta['_ayvpp_views'][0]) ? (int)$raw_meta['_ayvpp_views'][0] : 0);
            $duration = isset($raw_meta['duration'][0]) ? $raw_meta['duration'][0] : '';
            
            if ( !$image_url && $video_id ) {
                $image_url = 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg';
            }

            self::print_meta_tags( $title, $excerpt, $url, $image_url, 'video.other', $video_id );

            if ( self::$options['enable_schema'] === '1' ) {
                if ( $video_id ) {
                    $schema = array(
                        "@context"     => "https://schema.org",
                        "@type"        => "VideoObject",
                        "name"         => $title,
                        "description"  => $excerpt,
                        "thumbnailUrl" => array( $image_url ),
                        "uploadDate"   => get_the_date('c', $post->ID),
                        "embedUrl"     => "https://www.youtube.com/embed/" . $video_id
                    );
                    if ( $duration ) $schema['duration'] = $duration;
                    if ( $views > 0 ) {
                        $schema['interactionStatistic'] = array(
                            "@type" => "InteractionCounter",
                            "interactionType" => array("@type" => "WatchAction"),
                            "userInteractionCount" => $views
                        );
                    }
                    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>" . PHP_EOL;
                }

                $categories = get_the_category( $post->ID );
                $breadcrumbs = array(
                    "@context" => "https://schema.org",
                    "@type" => "BreadcrumbList",
                    "itemListElement" => array(
                        array( "@type" => "ListItem", "position" => 1, "name" => "Home", "item" => home_url('/') )
                    )
                );
                
                $pos = 2;
                if ( !empty($categories) ) {
                    $breadcrumbs["itemListElement"][] = array(
                        "@type" => "ListItem", "position" => $pos, "name" => $categories[0]->name, "item" => get_category_link($categories[0]->term_id)
                    );
                    $pos++;
                }
                $breadcrumbs["itemListElement"][] = array(
                    "@type" => "ListItem", "position" => $pos, "name" => $title, "item" => $canonical_url
                );
                
                echo '<script type="application/ld+json">' . wp_json_encode($breadcrumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>" . PHP_EOL;
            }
        } 
        elseif ( is_front_page() || is_home() || is_category() ) {
            $is_cat = is_category();
            $title = $is_cat ? single_cat_title( '', false ) . ' - ' . get_bloginfo( 'name' ) : get_bloginfo( 'name' );
            
            $excerpt = '';
            if ( $is_cat ) {
                $excerpt = wp_strip_all_tags( category_description() );
                if ( empty($excerpt) ) $excerpt = /* translators: %s: Category name */ sprintf( __( 'All videos from the category %s on vTubes.tokyo.', 'saiteki' ), single_cat_title( '', false ) );
            } else {
                $excerpt = get_bloginfo( 'description' );
            }
            
            $url = $is_cat ? get_category_link( get_queried_object()->term_id ) : home_url( '/' );
            echo '<link rel="canonical" href="' . esc_url($url) . '">' . PHP_EOL;
            $image_url = self::get_latest_grid_image();

            self::print_meta_tags( $title, $excerpt, $url, $image_url, 'website', '' );

            if ( self::$options['enable_schema'] === '1' ) {
                global $wp_query;
                if ( ! empty( $wp_query->posts ) ) {
                    $item_list = array(
                        "@context" => "https://schema.org",
                        "@type"    => "ItemList",
                        "itemListElement" => array()
                    );
                    $position = 1;
                    foreach ( $wp_query->posts as $grid_post ) {
                        $item_list['itemListElement'][] = array(
                            "@type"    => "ListItem",
                            "position" => $position,
                            "url"      => get_permalink($grid_post->ID),
                            "name"     => get_the_title($grid_post->ID)
                        );
                        $position++;
                    }
                    echo '<script type="application/ld+json">' . wp_json_encode($item_list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>" . PHP_EOL;
                }
            }
        }
        elseif ( is_tag() ) {
            // NEU: Tags strikt auf NOINDEX setzen, um SEO-Crawl-Budget zu schonen!
            $tag = get_queried_object();
            $url = get_tag_link( $tag->term_id );
            
            echo '<meta name="robots" content="noindex, follow">' . PHP_EOL;
            echo '<link rel="canonical" href="' . esc_url($url) . '">' . PHP_EOL;
            
            $title = single_tag_title( '', false ) . ' - ' . get_bloginfo( 'name' );
            self::print_meta_tags( $title, /* translators: %s: Tag name */ sprintf( __( 'Video archive for the tag %s', 'saiteki' ), single_tag_title( '', false ) ), $url, '', 'website', '' );
        }

        echo "" . PHP_EOL . PHP_EOL;
    }

    private static function print_meta_tags( $title, $description, $url, $image_url, $type, $video_id ) {
        echo '<meta name="description" content="' . esc_attr($description) . '">' . PHP_EOL;
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . PHP_EOL;
        echo '<meta property="og:type" content="' . esc_attr($type) . '">' . PHP_EOL;
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . PHP_EOL;
        
        if ( $image_url ) {
            echo '<meta property="og:image" content="' . esc_url($image_url) . '">' . PHP_EOL;
        }

        if ( self::$options['enable_twitter_cards'] === '1' && $video_id ) {
            echo '<meta name="twitter:card" content="player">' . PHP_EOL;
            echo '<meta name="twitter:player" content="https://www.youtube.com/embed/' . esc_attr($video_id) . '">' . PHP_EOL;
            echo '<meta name="twitter:player:width" content="1280">' . PHP_EOL;
            echo '<meta name="twitter:player:height" content="720">' . PHP_EOL;
            if ( $image_url ) echo '<meta name="twitter:image" content="' . esc_url($image_url) . '">' . PHP_EOL;
        } else {
            echo '<meta name="twitter:card" content="summary_large_image">' . PHP_EOL;
            if ( $image_url ) echo '<meta name="twitter:image" content="' . esc_url($image_url) . '">' . PHP_EOL;
        }
    }
}
