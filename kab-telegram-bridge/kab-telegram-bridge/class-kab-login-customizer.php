<?php
/**
 * Customizes the WordPress login page appearance and handles
 * AJAX operations for Telegram account linking/unlinking.
 *
 * Also implements smart redirect: when a user arrives from Moodle
 * (via the moodle_redirect_to parameter) they are sent back to Moodle
 * after successful Telegram login.
 */
class KAB_Login_Customizer {

    public static function init() {
        add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_page_styles' ) );
        add_action( 'wp_ajax_kab_unlink_telegram', array( __CLASS__, 'ajax_unlink_telegram' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_profile_scripts' ) );

        // Persist the moodle_redirect_to param through the login form.
        add_action( 'login_form', array( __CLASS__, 'persist_moodle_redirect' ) );

        // Custom Telegram login page — works even when wp-login.php is hidden.
        add_action( 'template_redirect', array( __CLASS__, 'handle_telegram_login_page' ) );

        // Intercept ANY redirect during Telegram login to redirect to Moodle.
        // This works regardless of WP Telegram Login plugin version.
        add_filter( 'wp_redirect', array( __CLASS__, 'intercept_telegram_login_redirect' ), 999 );

        // Also try the plugin-specific filter (may exist in other versions).
        add_filter( 'wptelegram_login_user_redirect_to', array( __CLASS__, 'custom_redirect_after_login' ), 10, 2 );

        // Diagnostic: fires after EB SSO (priority 10) to detect if it redirected.
        // If this code runs, EB SSO did NOT call wp_redirect + exit.
        add_action( 'wp_login', array( __CLASS__, 'diagnose_after_eb_sso' ), 15, 2 );
    }

    /**
     * Handle requests with ?telegram_login=1 on the front-end.
     *
     * This renders a standalone Telegram login page that works regardless
     * of whether wp-login.php is accessible (security plugins may hide it).
     */
    public static function handle_telegram_login_page() {
        kab_log( 'handle_telegram_login_page called. GET=' . wp_json_encode( $_GET ) );
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( empty( $_GET['telegram_login'] ) ) {
            // If this is an EB SSO verification request, log the auth state.
            // phpcs:ignore WordPress.Security.NonceVerification
            if ( isset( $_GET['wdmaction'] ) && 'login' === $_GET['wdmaction'] ) {
                kab_log( 'EB SSO verification request detected. is_user_logged_in='
                    . ( is_user_logged_in() ? 'YES (user_id=' . get_current_user_id() . ')' : 'NO' ) );
            }
            kab_log( 'handle_telegram_login_page: No telegram_login param, skipping.' );
            return;
        }
        kab_log( 'handle_telegram_login_page: Processing telegram_login page.' );

        // phpcs:ignore WordPress.Security.NonceVerification
        $moodle_url = isset( $_GET['moodle_redirect_to'] )
            ? esc_url_raw( wp_unslash( $_GET['moodle_redirect_to'] ) )
            : '';

        // Store moodle_redirect_to so it survives the Telegram auth callback.
        // Use both cookie and transient for reliability (cookie may not arrive
        // if COOKIE_DOMAIN/COOKIEPATH differ between the initial request and callback).
        if ( $moodle_url && self::is_allowed_moodle_url( $moodle_url ) ) {
            setcookie( 'kab_moodle_redirect', $moodle_url, time() + 600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
            // Transient keyed by visitor IP — short-lived, used as fallback.
            $transient_key = 'kab_moodle_redir_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
            set_transient( $transient_key, $moodle_url, 600 );
        }

        // If already logged in, redirect immediately.
        if ( is_user_logged_in() ) {
            if ( $moodle_url && self::is_allowed_moodle_url( $moodle_url ) ) {
                wp_redirect( $moodle_url );
                exit;
            }

            $eb_page_id = get_option( 'eb_useraccount_page_id' );
            if ( $eb_page_id ) {
                $account_url = get_permalink( $eb_page_id );
                if ( $account_url ) {
                    wp_redirect( $account_url );
                    exit;
                }
            }

            wp_redirect( home_url() );
            exit;
        }

        // Render standalone Telegram login page.
        self::render_telegram_login_page();
        exit;
    }

    /**
     * Render a clean standalone page with the Telegram login widget.
     */
    private static function render_telegram_login_page() {
        $site_name = get_bloginfo( 'name' );
        $site_icon = get_site_icon_url( 64 );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $site_name ); ?> — Telegram Login</title>
            <?php wp_head(); ?>
            <style>
                body { background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
                .kab-telegram-login-box { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.13); padding: 40px; max-width: 400px; width: 90%; text-align: center; }
                .kab-telegram-login-box h1 { font-size: 20px; margin: 0 0 8px; }
                .kab-telegram-login-box p { color: #72777c; margin: 0 0 24px; font-size: 14px; }
                .kab-telegram-login-box .site-logo { margin-bottom: 16px; }
                .kab-telegram-login-box .site-logo img { width: 64px; height: 64px; border-radius: 8px; }
            </style>
        </head>
        <body>
            <div class="kab-telegram-login-box">
                <?php if ( $site_icon ) : ?>
                    <div class="site-logo"><img src="<?php echo esc_url( $site_icon ); ?>" alt=""></div>
                <?php endif; ?>
                <h1><?php echo esc_html( $site_name ); ?></h1>
                <p><?php esc_html_e( 'Log in with your Telegram account' ); ?></p>
                <?php
                if ( function_exists( 'wptelegram_login' ) ) {
                    wptelegram_login(
                        array(
                            'button_style'    => 'large',
                            'show_user_photo' => false,
                            'corner_radius'   => 15,
                            'show_if_user_is' => 'logged_out',
                        )
                    );
                } else {
                    echo '<p style="color:#d63638;">' . esc_html__( 'Telegram Login plugin is not active.' ) . '</p>';
                }
                ?>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    /**
     * Add styles to the WordPress login page to position the Telegram button.
     */
    public static function login_page_styles() {
        ?>
        <style>
            .wptelegram-login-wrap {
                text-align: center;
                margin: 16px 0 0;
                padding: 12px 0;
                border-top: 1px solid #ddd;
            }
            .wptelegram-login-wrap::before {
                content: "<?php echo esc_attr__( 'Or sign in with Telegram' ); ?>";
                display: block;
                margin-bottom: 12px;
                color: #72777c;
                font-size: 13px;
            }
        </style>
        <?php
    }

    /**
     * After successful Telegram login, redirect based on context:
     * - If moodle_redirect_to is present, go back to Moodle
     * - Otherwise, go to Edwiser Bridge user account page
     * - Fallback: WP admin dashboard
     *
     * @param string  $redirect_to Current redirect URL.
     * @param WP_User $user        Logged-in user.
     * @return string Final redirect URL.
     */
    public static function custom_redirect_after_login( $redirect_to, $user = null ) {
        try {
            kab_log( 'custom_redirect_after_login called. redirect_to=' . $redirect_to );

            $moodle_url = self::get_moodle_redirect_url();

            if ( $moodle_url ) {
                kab_log( 'custom_redirect_after_login: Moodle redirect: ' . $moodle_url );
                return $moodle_url;
            }

            // Redirect to Edwiser Bridge user account page if available.
            $eb_page_id = get_option( 'eb_useraccount_page_id' );
            if ( $eb_page_id ) {
                $account_url = get_permalink( $eb_page_id );
                if ( $account_url ) {
                    return $account_url;
                }
            }

            return admin_url();
        } catch ( \Throwable $e ) {
            kab_log( 'custom_redirect_after_login ERROR: ' . $e->getMessage() );
            return $redirect_to;
        }
    }

    /**
     * Diagnostic: runs on wp_login at priority 15, AFTER Edwiser Bridge SSO
     * (priority 10). If this code executes, EB SSO did NOT call wp_redirect + exit.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     */
    public static function diagnose_after_eb_sso( $user_login, $user ) {
        if ( ! kab_is_telegram_callback() ) {
            return;
        }

        kab_log( 'diagnose_after_eb_sso: EB SSO did NOT redirect (still running at priority 15).' );
        kab_log( 'diagnose_after_eb_sso: user=' . $user_login . ', user_id=' . ( $user instanceof WP_User ? $user->ID : 'unknown' ) );

        // Log EB SSO availability.
        $eb_available  = function_exists( 'edwiser_bridge_instance' ) ? 'yes' : 'no';
        $eb_connection = get_option( 'eb_connection' );
        kab_log( 'diagnose_after_eb_sso: edwiser_bridge_instance=' . $eb_available );
        kab_log( 'diagnose_after_eb_sso: eb_connection=' . wp_json_encode( $eb_connection ) );

        // Check what EB SSO options are stored.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $sso_options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%sso%' OR option_name LIKE '%eb_general%' LIMIT 20",
            ARRAY_A
        );
        kab_log( 'diagnose_after_eb_sso: SSO-related options=' . wp_json_encode( $sso_options ) );

        // Check if login_redirect filter returns something different (EB SSO may use it).
        $test_url = home_url( '/' );
        $filtered = apply_filters( 'login_redirect', $test_url, $test_url, $user );
        if ( $filtered !== $test_url ) {
            kab_log( 'diagnose_after_eb_sso: login_redirect filter returned: ' . $filtered );
        } else {
            kab_log( 'diagnose_after_eb_sso: login_redirect filter did NOT modify URL.' );
        }
    }

    /**
     * Intercept wp_redirect during Telegram login callback to redirect to Moodle.
     *
     * This fires for ALL wp_redirect calls, so we only modify the redirect
     * when we detect a Telegram login callback AND have a stored Moodle URL.
     *
     * @param string $location The redirect URL.
     * @return string Modified redirect URL.
     */
    public static function intercept_telegram_login_redirect( $location ) {
        // Only intercept during Telegram login callback (AJAX or REST API).
        if ( ! kab_is_telegram_callback() ) {
            return $location;
        }

        kab_log( 'intercept_telegram_login_redirect: Original location: ' . $location );

        $moodle_url = self::get_moodle_redirect_url();
        if ( ! $moodle_url ) {
            kab_log( 'intercept_telegram_login_redirect: No Moodle URL found, keeping original.' );
            return $location;
        }

        // If Edwiser Bridge SSO is redirecting through its endpoint,
        // preserve the SSO flow and append our Moodle URL as the final
        // destination so the user ends up on the right page after SSO.
        if ( false !== strpos( $location, '/auth/edwiserbridge/' ) ) {
            $modified = remove_query_arg( array( 'wantsurl', 'redirect_to' ), $location );
            $modified = add_query_arg( 'wantsurl', rawurlencode( $moodle_url ), $modified );
            kab_log( 'intercept_telegram_login_redirect: EB SSO detected, modified: ' . $modified );
            return $modified;
        }

        kab_log( 'intercept_telegram_login_redirect: Redirecting to Moodle: ' . $moodle_url );
        return $moodle_url;
    }

    /**
     * Get the Moodle redirect URL from cookie or request.
     *
     * @return string|false Moodle URL or false.
     */
    private static function get_moodle_redirect_url() {
        // 1. Check request parameter first.
        // phpcs:ignore WordPress.Security.NonceVerification
        $moodle_url = isset( $_REQUEST['moodle_redirect_to'] )
            ? esc_url_raw( wp_unslash( $_REQUEST['moodle_redirect_to'] ) )
            : '';

        // 2. Fallback: check the cookie set by handle_telegram_login_page().
        if ( empty( $moodle_url ) && ! empty( $_COOKIE['kab_moodle_redirect'] ) ) {
            $moodle_url = esc_url_raw( wp_unslash( $_COOKIE['kab_moodle_redirect'] ) );
            setcookie( 'kab_moodle_redirect', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }

        // 3. Fallback: check the transient (cookie may not have survived the redirect).
        if ( empty( $moodle_url ) ) {
            $transient_key = 'kab_moodle_redir_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
            $moodle_url    = get_transient( $transient_key );
            if ( $moodle_url ) {
                delete_transient( $transient_key );
            }
        }

        if ( $moodle_url && self::is_allowed_moodle_url( $moodle_url ) ) {
            return $moodle_url;
        }

        return false;
    }

    /**
     * Validate that the Moodle redirect URL belongs to the expected domain.
     *
     * @param string $url URL to validate.
     * @return bool
     */
    private static function is_allowed_moodle_url( $url ) {
        $allowed_host = 'edu.kabacademy.com';
        $parsed       = wp_parse_url( $url );

        return isset( $parsed['host'] ) && $parsed['host'] === $allowed_host;
    }

    /**
     * Keep the moodle_redirect_to parameter through the login form as a hidden field.
     */
    public static function persist_moodle_redirect() {
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! isset( $_GET['moodle_redirect_to'] ) ) {
            return;
        }

        $url = esc_url( wp_unslash( $_GET['moodle_redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( $url ) {
            echo '<input type="hidden" name="moodle_redirect_to" value="' . esc_attr( $url ) . '" />';
        }
    }

    /**
     * AJAX handler to unlink a Telegram account from the current user.
     */
    public static function ajax_unlink_telegram() {
        check_ajax_referer( 'kab_telegram_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( __( 'Not logged in.' ) );
        }

        if ( defined( 'WPTELEGRAM_USER_ID_META_KEY' ) ) {
            delete_user_meta( $user_id, WPTELEGRAM_USER_ID_META_KEY );
        }

        $username_key = defined( 'WPTELEGRAM_USERNAME_META_KEY' )
            ? WPTELEGRAM_USERNAME_META_KEY
            : 'wptelegram_username';
        delete_user_meta( $user_id, $username_key );

        wp_send_json_success( __( 'Telegram account unlinked successfully.' ) );
    }

    /**
     * Enqueue inline script on the profile page for the unlink button.
     *
     * @param string $hook Current admin page.
     */
    public static function enqueue_profile_scripts( $hook ) {
        if ( 'profile.php' !== $hook && 'user-edit.php' !== $hook ) {
            return;
        }

        $nonce = wp_create_nonce( 'kab_telegram_nonce' );
        $js    = <<<JS
jQuery(function($){
    $('#kab-unlink-telegram').on('click',function(){
        if(!confirm('Are you sure you want to disconnect your Telegram account?'))return;
        $.post(ajaxurl,{action:'kab_unlink_telegram',nonce:'{$nonce}'},function(r){
            if(r.success){location.reload();}else{alert('Error: '+r.data);}
        });
    });
});
JS;
        wp_add_inline_script( 'jquery', $js );
    }
}
