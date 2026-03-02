<?php
/**
 * Enforces existing-users-only policy for Telegram login.
 *
 * The WP Telegram Login plugin has a built-in "Disable Signup" setting.
 * This class provides a programmatic guarantee via filter hooks so that
 * even if someone changes the plugin setting, the policy stays enforced.
 */
class KAB_Telegram_Guard {

    public static function init() {
        // Force disable_signup via the plugin's stored-option filter.
        add_filter(
            'wpsocio\wputils\options_wptelegram_login_get_disable_signup',
            '__return_true',
            999
        );

        // Force disable_signup via the login-handler filter.
        add_filter( 'wptelegram_login_disable_signup', '__return_true', 999 );

        // Show a helpful message when an unlinked Telegram user is rejected.
        add_action( 'login_message', array( __CLASS__, 'add_linking_instructions' ) );
    }

    /**
     * Display instructions when a Telegram login attempt is rejected
     * because the account is not linked to any WordPress user.
     */
    public static function add_linking_instructions() {
        if ( ! isset( $_GET['wptelegram_login_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        echo '<div id="login_error">';
        echo '<strong>' . esc_html__( 'Telegram Login' ) . ':</strong> ';
        echo esc_html__(
            'Your Telegram account is not linked to any account on this site. '
            . 'Please log in with your username and password first, then link '
            . 'your Telegram account from your profile page.'
        );
        echo '</div>';
    }
}
