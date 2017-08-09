<?php
/*
Plugin Name: YouTube Live
Plugin URI: https://github.com/macbookandrew/wp-youtube-live
Description: Displays the current YouTube live video from a specified channel
Version: 1.6.2
Author: Andrew Minion
Author URI: https://andrewrminion.com/
*/

if (!defined('ABSPATH')) {
    exit;
}

CONST WP_YOUTUBE_LIVE_VERSION = '1.6.2';

include('inc/admin.php');

/**
 * Enqueue frontend scripts
 */
function youtube_live_scripts() {
    wp_register_script( 'wp-youtube-live', plugin_dir_url( __FILE__ ) . 'js/wp-youtube-live.min.js', array( 'jquery' ), WP_YOUTUBE_LIVE_VERSION, true );
    wp_register_style( 'wp-youtube-live', plugin_dir_url( __FILE__ ) . 'css/wp-youtube-live.css', array(), WP_YOUTUBE_LIVE_VERSION );
}
add_action( 'wp_enqueue_scripts', 'youtube_live_scripts' );


/**
 * Create shortcode
 * @param  array  $atts shortcode parameters
 * @return string HTML shortcode output
 */
function output_youtube_live( $atts ) {
    // enqueue assets
    wp_enqueue_script( 'wp-youtube-live' );
    wp_enqueue_style( 'wp-youtube-live' );

    // get plugin settings
    $settings = get_option( 'youtube_live_settings' );

    // get shortcode attributes
    $shortcode_attributes = shortcode_atts( array (
        'width'             => $settings['default_width'],
        'height'            => $settings['default_height'],
        'autoplay'          => 0,
        'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
        'no_stream_message' => NULL,
        'auto_refresh'      => $settings['auto_refresh'],
        'refreshInterval'   => apply_filters( 'wp_youtube_live_transient_timeout', '30' ),
    ), $atts );

    wp_add_inline_script( 'wp-youtube-live', 'var wpYouTubeLive = ' . json_encode( $shortcode_attributes ) );

    return get_youtube_live_content( $shortcode_attributes );
}
add_shortcode( 'youtube_live', 'output_youtube_live' );

/**
 * Add ajax handlers
 */
add_action( 'wp_ajax_load_youtube_live', 'get_youtube_live_content' );
add_action( 'wp_ajax_nopriv_load_youtube_live', 'get_youtube_live_content' );

/**
 * Output YouTube Live content
 * @param  array  $youtube_settings array of settings
 * @return string JSON or HTML content
 */
function get_youtube_live_content( $youtube_settings ) {
    // load embed class
    require_once( 'inc/EmbedYoutubeLiveStreaming.php' );

    // get saved options
    $youtube_options = get_option( 'youtube_live_settings' );

    // set up player
    $youtube_live = new EmbedYoutubeLiveStreaming( $youtube_options['youtube_live_channel_id'], $youtube_options['youtube_live_api_key'] );
    $youtube_live->subdomain = ( $youtube_options['subdomain'] ? $youtube_options['subdomain'] : 'www' );
    $youtube_live->embed_width = ( $_POST['isAjax'] ? esc_attr( $_POST['width'] ) : $youtube_settings['width'] );
    $youtube_live->embed_height = ( $_POST['isAjax'] ? esc_attr( $_POST['height'] ) : $youtube_settings['height'] );
    $youtube_live->embed_autoplay = ( $_POST['isAjax'] ? esc_attr( $_POST['autoplay'] ) : $youtube_settings['autoplay'] );

    // set default message
    if ( 'no_message' == $youtube_settings['no_stream_message'] ) {
        $no_stream_message = NULL;
    } elseif ( $youtube_settings['no_stream_message'] ) {
        $no_stream_message = $youtube_settings['no_stream_message'];
    } else {
        $no_stream_message = apply_filters( 'wp_youtube_live_no_stream_available', '<p>Sorry, there&rsquo;s no live stream at the moment. Please check back later or take a look at <a target="_blank" href="https://youtube.com/channel/' . $youtube_options['youtube_live_channel_id'] . '">all our videos</a>.</p>
        <p><button type="button" class="button" id="check-again">Check again</button><span class="spinner" style="display:none;"></span></p>' );
    }

    // start output
    ob_start();
    if ( $no_stream_message || $youtube_live->isLive ) {
        echo '<span class="wp-youtube-live ' . ( $youtube_live->isLive ? 'live' : 'dead' ) . '">';
    }

    if ( $youtube_live->isLive ) {
        $is_live = true;
        echo $youtube_live->embedCode();
    } else {
        $is_live = false;
        echo $no_stream_message;

        if ( $youtube_options['show_channel_if_dead'] === 'true' ) {
            $youtube_live->getVideoInfo( 'channel' );
            echo $youtube_live->embedCode();
        } elseif ( isset( $youtube_options['fallback_video'] ) ) {
            echo wp_oembed_get( esc_attr( $youtube_options['fallback_video'] ) );
        }
    }

    // debugging
    if ( get_option( 'youtube_live_settings', 'debugging' ) && is_user_logged_in() ) {
        $debugging_code = var_export( $youtube_live, true );
        echo '<!-- YouTube Live debugging: ' . "\n" . $debugging_code . "\n" . ' -->';
    }

    if ( $no_stream_message || $youtube_live->isLive ) {
        echo '</span>';
    }

    // handle ajax
    if ( $_POST['isAjax'] ) {
        if ( $_POST['requestType'] != 'refresh' || $is_live ) {
            $json_data['content'] = ob_get_clean();
        } else {
            ob_clean();
        }
        $json_data['live'] = $youtube_live->isLive;
        echo json_encode( $json_data, JSON_FORCE_OBJECT );
        wp_die();
    } else {
        return ob_get_clean();
    }
}
