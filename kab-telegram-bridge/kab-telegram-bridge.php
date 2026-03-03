<?php
/**
 * Plugin Name: KAB Academy Telegram Bridge
 * Description: Customizes WP Telegram Login integration for kabacademy.com.
 *              Enforces existing-users-only login, ensures Moodle linking via
 *              Edwiser Bridge, and provides custom widget placement.
 * Version: 1.0.1
 * Author: KAB Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KAB_TELEGRAM_BRIDGE_DIR', plugin_dir_path( __FILE__ ) . 'kab-telegram-bridge/' );
define( 'KAB_TELEGRAM_BRIDGE_VER', '1.0.1' );

/**
 * Write to our own log file so we can debug without WP_DEBUG.
 *
 * @param string $message Log message.
 */
function kab_log( $message ) {
    $log_file = WP_CONTENT_DIR . '/kab-debug.log';
    $time     = gmdate( 'Y-m-d H:i:s' );
    file_put_contents( $log_file, "[{$time}] {$message}\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
}

// Wrap require_once in try/catch — parse errors in class files would be fatal.
try {
    require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-telegram-guard.php';
    require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-moodle-linker.php';
    require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-telegram-widget.php';
    require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-login-customizer.php';
    kab_log( 'All class files loaded OK.' );
} catch ( \Throwable $e ) {
    kab_log( 'FATAL: Failed to load class files — ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
    return; // Stop plugin completely.
}

// Register a shutdown function to catch fatal errors that try/catch cannot.
register_shutdown_function( function () {
    $error = error_get_last();
    if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
        kab_log( 'SHUTDOWN FATAL: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] );
    }
} );

add_action( 'plugins_loaded', function () {
    kab_log( 'plugins_loaded fired. WPTELEGRAM_LOGIN_VER=' . ( defined( 'WPTELEGRAM_LOGIN_VER' ) ? WPTELEGRAM_LOGIN_VER : 'NOT DEFINED' ) );

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
        kab_log( 'Guard init OK.' );
        KAB_Moodle_Linker::init();
        kab_log( 'Moodle Linker init OK.' );
        KAB_Telegram_Widget::init();
        kab_log( 'Widget init OK.' );
        KAB_Login_Customizer::init();
        kab_log( 'Login Customizer init OK.' );
    } catch ( \Throwable $e ) {
        kab_log( 'INIT ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
    }
}, 20 );
