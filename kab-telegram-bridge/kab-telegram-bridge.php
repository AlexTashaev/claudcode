<?php
/**
 * Plugin Name: KAB Academy Telegram Bridge
 * Description: Customizes WP Telegram Login integration for kabacademy.com.
 *              Enforces existing-users-only login, ensures Moodle linking via
 *              Edwiser Bridge, and provides custom widget placement.
 * Version: 2.1.0
 * Author: KAB Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KAB_TELEGRAM_BRIDGE_DIR', plugin_dir_path( __FILE__ ) . 'kab-telegram-bridge/' );
define( 'KAB_TELEGRAM_BRIDGE_VER', '2.1.0' );

// ============================================================
// SendPulse & Lesson settings — change these for your setup.
// ============================================================
// SendPulse API credentials (https://login.sendpulse.com/account/api/).
if ( ! defined( 'KAB_SP_CLIENT_ID' ) ) {
    define( 'KAB_SP_CLIENT_ID', '' ); // TODO: Set your SendPulse Client ID.
}
if ( ! defined( 'KAB_SP_CLIENT_SECRET' ) ) {
    define( 'KAB_SP_CLIENT_SECRET', '' ); // TODO: Set your SendPulse Client Secret.
}
// SendPulse Telegram bot ID (from bot settings in SendPulse dashboard).
if ( ! defined( 'KAB_SP_BOT_ID' ) ) {
    define( 'KAB_SP_BOT_ID', '' ); // TODO: Set your SendPulse Bot ID.
}
// URL of the first Moodle lesson to redirect to after Telegram connect.
if ( ! defined( 'KAB_FIRST_LESSON_URL' ) ) {
    define( 'KAB_FIRST_LESSON_URL', '' ); // TODO: Set lesson URL, e.g. https://edu.kabacademy.com/mod/lesson/view.php?id=123
}

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
    require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-sendpulse.php';
    require_once KAB_TELEGRAM_BRIDGE_DIR . 'class-kab-thankyou.php';
} catch ( \Throwable $e ) {
    kab_log( 'FATAL: Failed to load class files — ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
    return;
}

// Register a shutdown function to catch fatal errors that try/catch cannot.
register_shutdown_function( function () {
    $error = error_get_last();
    if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
        kab_log( 'SHUTDOWN FATAL: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] );
    }
} );

// Log Telegram callbacks on init for debugging.
add_action( 'init', function () {
    if ( kab_is_telegram_callback() ) {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        kab_log( 'Telegram callback detected. URI=' . $uri );
    }
}, 1 );

// GLOBAL redirect interceptor: if we have a post-login return cookie,
// hijack any EB SSO redirect on the next page load.
add_filter( 'wp_redirect', function ( $location ) {
    if ( empty( $_COOKIE['kab_after_tg_login'] ) ) {
        return $location;
    }

    $loc_lower = strtolower( $location );
    if ( false !== strpos( $loc_lower, 'edu.kabacademy' )
        || false !== strpos( $loc_lower, 'edwiserbridge' )
    ) {
        $return_url = esc_url_raw( wp_unslash( $_COOKIE['kab_after_tg_login'] ) );
        setcookie( 'kab_after_tg_login', '', time() - 3600, '/', '', is_ssl(), true );
        if ( $return_url && wp_validate_redirect( $return_url, false ) ) {
            kab_log( 'Global interceptor: hijacked EB redirect to ' . $return_url );
            return $return_url;
        }
    }
    return $location;
}, 1 );


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
        KAB_SendPulse::init();
        KAB_ThankYou::init();
    } catch ( \Throwable $e ) {
        kab_log( 'INIT ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
    }
}, 20 );
