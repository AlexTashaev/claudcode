<?php
/**
 * Plugin Name: KAB Academy Telegram Bridge
 * Description: Customizes WP Telegram Login integration for kabacademy.com.
 *              Enforces existing-users-only login, ensures Moodle linking via
 *              Edwiser Bridge, and provides custom widget placement.
 * Version: 1.3.0
 * Author: KAB Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KAB_TELEGRAM_BRIDGE_DIR', plugin_dir_path( __FILE__ ) . 'kab-telegram-bridge/' );
define( 'KAB_TELEGRAM_BRIDGE_VER', '1.3.0' );

/**
 * Write to our own log file so we can debug without WP_DEBUG.
 * Log is stored outside the webroot-accessible directory structure.
 *
 * @param string $message Log message.
 */
function kab_log( $message ) {
    $log_dir = WP_CONTENT_DIR . '/kab-logs';
    if ( ! is_dir( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
        // Protect directory from web access.
        file_put_contents( $log_dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
        file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' ); // phpcs:ignore
    }
    $log_file = $log_dir . '/kab-debug.log';
    $time     = gmdate( 'Y-m-d H:i:s' );
    file_put_contents( $log_file, "[{$time}] {$message}\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
}

/**
 * Detect whether the current request is a Telegram login callback.
 * Works with both admin-ajax (v1) and REST API (v2+) versions of WP Telegram Login.
 *
 * @return bool
 */
function kab_is_telegram_callback() {
    // Legacy: admin-ajax.php?action=wptelegram_login.
    // phpcs:ignore WordPress.Security.NonceVerification
    if ( isset( $_REQUEST['action'] ) && 'wptelegram_login' === $_REQUEST['action'] ) {
        return true;
    }

    // Modern: REST API endpoint /wp-json/wptelegram-login/v1/...
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    if ( false !== strpos( $uri, 'wptelegram-login' ) ) {
        return true;
    }

    return false;
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

// Early callback detection — catches ALL Telegram login callbacks (AJAX and REST API).
add_action( 'init', function () {
    if ( kab_is_telegram_callback() ) {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        kab_log( '>>> TELEGRAM CALLBACK on init. URI=' . $uri );
    }
}, 1 );

// Detect any login — wp_set_auth_cookie fires even when wp_login does not.
add_action( 'set_auth_cookie', function ( $auth_cookie, $expire, $expiration, $user_id ) {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'unknown';
    kab_log( 'set_auth_cookie fired for user_id=' . $user_id . ' | URI=' . $uri );
}, 10, 4 );

// Detect wp_login — should fire on login but may not in REST context.
add_action( 'wp_login', function ( $user_login ) {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'unknown';
    kab_log( 'wp_login fired for: ' . $user_login . ' | URI=' . $uri );
}, 1 );


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
