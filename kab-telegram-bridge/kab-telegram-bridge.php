<?php
/**
 * Plugin Name: KAB Academy Telegram Bridge
 * Description: Customizes WP Telegram Login integration for kabacademy.com.
 *              Enforces existing-users-only login, ensures Moodle linking via
 *              Edwiser Bridge, and provides custom widget placement.
 * Version: 1.0.0
 * Author: KAB Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KAB_TELEGRAM_BRIDGE_DIR', plugin_dir_path( __FILE__ ) . 'kab-telegram-bridge/' );
define( 'KAB_TELEGRAM_BRIDGE_VER', '1.0.0' );

require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-telegram-guard.php';
require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-moodle-linker.php';
require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-telegram-widget.php';
require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-login-customizer.php';

add_action( 'plugins_loaded', function () {
    // Only initialize if WP Telegram Login is active.
    if ( ! defined( 'WPTELEGRAM_LOGIN_VER' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>KAB Telegram Bridge:</strong> ';
            esc_html_e( 'WP Telegram Login & Register plugin is required but not active.' );
            echo '</p></div>';
        } );
        return;
    }

    try {
        KAB_Telegram_Guard::init();
        KAB_Moodle_Linker::init();
        KAB_Telegram_Widget::init();
        KAB_Login_Customizer::init();
    } catch ( \Throwable $e ) {
        error_log( 'KAB Telegram Bridge: Init failed — ' . $e->getMessage() );
    }
}, 20 );
