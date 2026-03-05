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

    /** @var bool True during the link-Telegram-to-existing-account flow. */
    private static $is_linking_flow = false;

    public static function init() {
        add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_page_styles' ) );
        add_action( 'wp_ajax_kab_unlink_telegram', array( __CLASS__, 'ajax_unlink_telegram' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_profile_scripts' ) );

        // Persist the moodle_redirect_to param through the login form.
        add_action( 'login_form', array( __CLASS__, 'persist_moodle_redirect' ) );

        // Custom Telegram login page — works even when wp-login.php is hidden.
        add_action( 'template_redirect', array( __CLASS__, 'handle_telegram_login_page' ) );

        // Telegram account linking page — shown when Telegram is not linked.
        add_action( 'template_redirect', array( __CLASS__, 'handle_telegram_link_page' ) );

        // Process the link form submission (must run on init before any output).
        add_action( 'init', array( __CLASS__, 'handle_telegram_link_submit' ) );

        // After EB SSO returns user to WP, redirect to the Moodle target page.
        add_action( 'template_redirect', array( __CLASS__, 'redirect_to_pending_moodle_target' ), 1 );

        // PRIMARY: Redirect BEFORE EB SSO gets a chance to run.
        // EB SSO hooks wp_login at priority 10. We hook at priority 8 and call
        // wp_redirect + exit, so EB SSO never executes during Telegram login.
        add_action( 'wp_login', array( __CLASS__, 'redirect_before_eb_sso' ), 8, 2 );

        // BACKUP: Intercept wp_redirect in case EB SSO still fires.
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
     * After EB SSO establishes the Moodle session and redirects back to WP,
     * redirect the user to the Moodle page they originally wanted.
     */
    public static function redirect_to_pending_moodle_target() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        if ( empty( $_COOKIE['kab_pending_moodle_target'] ) ) {
            return;
        }

        $moodle_target = esc_url_raw( wp_unslash( $_COOKIE['kab_pending_moodle_target'] ) );
        // Clear cookie immediately.
        setcookie( 'kab_pending_moodle_target', '', time() - 3600, '/', '', is_ssl(), false );

        if ( ! $moodle_target || ! self::is_allowed_moodle_url( $moodle_target ) ) {
            return;
        }

        // Don't redirect if we're already processing a Telegram callback.
        if ( kab_is_telegram_callback() ) {
            return;
        }

        kab_log( 'redirect_to_pending_moodle_target: Redirecting to ' . $moodle_target );
        wp_redirect( $moodle_target );
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
     * PRIMARY redirect handler: runs on wp_login at priority 8.
     *
     * EB SSO hooks wp_login at priority 10 and does a hard redirect to Moodle
     * (possibly via header() directly, bypassing wp_redirect filters).
     * By hooking at priority 8, we redirect and exit BEFORE EB SSO runs.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     */
    public static function redirect_before_eb_sso( $user_login, $user ) {
        kab_log( 'redirect_before_eb_sso: called for user=' . $user_login . ' is_tg_cb=' . ( kab_is_telegram_callback() ? 'YES' : 'NO' ) );
        kab_log( 'redirect_before_eb_sso: COOKIE keys=' . implode( ',', array_keys( $_COOKIE ?? array() ) ) );
        kab_log( 'redirect_before_eb_sso: REQUEST keys=' . implode( ',', array_keys( $_REQUEST ?? array() ) ) );
        kab_log( 'redirect_before_eb_sso: URI=' . ( $_SERVER['REQUEST_URI'] ?? 'unknown' ) );

        if ( ! kab_is_telegram_callback() ) {
            return;
        }

        kab_log( 'redirect_before_eb_sso: REQUEST redirect_to=' . ( $_REQUEST['redirect_to'] ?? 'NOT SET' ) );

        // Moodle redirect takes priority — let EB SSO establish the Moodle session.
        // We store the target in a cookie so we can redirect AFTER SSO completes.
        $moodle_url = self::get_moodle_redirect_url();
        if ( $moodle_url ) {
            kab_log( 'redirect_before_eb_sso: Moodle URL found (' . $moodle_url . '), saving target and deferring to EB SSO.' );
            setcookie( 'kab_pending_moodle_target', $moodle_url, time() + 300, '/', '', is_ssl(), false );
            return;
        }

        // WordPress return URL — redirect immediately, before EB SSO.
        $return_url = self::get_return_url();
        if ( $return_url ) {
            kab_log( 'redirect_before_eb_sso: FOUND return URL: ' . $return_url );

            // Set post-login cookie so any EB SSO redirect on the NEXT page load
            // will also be intercepted (global wp_redirect filter checks this).
            setcookie( 'kab_after_tg_login', $return_url, time() + 120, '/', '', is_ssl(), true );

            // Remove ALL remaining wp_login hooks to prevent EB SSO from running.
            remove_all_actions( 'wp_login' );

            kab_log( 'redirect_before_eb_sso: REDIRECTING to ' . $return_url . ' (EB SSO hooks removed)' );
            wp_redirect( $return_url );
            exit;
        }

        // No return URL found — set a fallback cookie with the referring page.
        $referer = wp_get_referer();
        if ( $referer && wp_validate_redirect( $referer, false ) ) {
            kab_log( 'redirect_before_eb_sso: No return URL but have referer: ' . $referer );
            setcookie( 'kab_after_tg_login', $referer, time() + 120, '/', '', is_ssl(), true );
            remove_all_actions( 'wp_login' );
            wp_redirect( $referer );
            exit;
        }

        kab_log( 'redirect_before_eb_sso: No return URL AND no referer, deferring to EB SSO.' );
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

            // Return user to the page they were on before Telegram login.
            $return_url = self::get_return_url();
            if ( $return_url ) {
                kab_log( 'custom_redirect_after_login: Return URL: ' . $return_url );
                return $return_url;
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
     * Intercept wp_redirect during Telegram login callback.
     *
     * This fires for ALL wp_redirect calls, so we only modify the redirect
     * when we detect a Telegram login callback AND have a target URL.
     *
     * Key insight: Edwiser Bridge SSO hooks wp_login and redirects to
     * edu.kabacademy.com/auth/edwiserbridge/?wantsurl=... Moodle validates
     * wantsurl and only accepts Moodle-domain URLs, so we cannot put a
     * WordPress URL there. Instead:
     * - For Moodle targets: modify wantsurl (same domain, will be accepted).
     * - For WordPress targets: bypass EB SSO entirely and redirect to WP page.
     *
     * @param string $location The redirect URL.
     * @return string Modified redirect URL.
     */
    public static function intercept_telegram_login_redirect( $location ) {
        $is_tg_cb  = kab_is_telegram_callback();
        $is_linking = self::$is_linking_flow;

        kab_log( 'intercept_tg_redirect: location=' . $location
            . ' is_tg_cb=' . ( $is_tg_cb ? 'YES' : 'NO' )
            . ' is_linking=' . ( $is_linking ? 'YES' : 'NO' ) );

        if ( ! $is_tg_cb && ! $is_linking ) {
            return $location;
        }

        $moodle_url = self::get_moodle_redirect_url();

        // --- Unlinked Telegram account: redirect to linking page instead of error ---
        if ( $is_tg_cb && false !== strpos( $location, 'wptelegram_login_error' ) ) {
            $tg_data = self::capture_telegram_data();
            if ( $tg_data ) {
                $token = wp_generate_password( 32, false );
                set_transient( 'kab_tg_link_' . $token, $tg_data, 600 );

                $link_url = home_url( '/?telegram_link=1&token=' . $token );
                if ( $moodle_url ) {
                    $link_url = add_query_arg( 'moodle_redirect_to', rawurlencode( $moodle_url ), $link_url );
                }
                kab_log( 'intercept_tg_redirect: Unlinked account, redirecting to link page. TG_ID=' . $tg_data['id'] );
                return $link_url;
            }
            kab_log( 'intercept_tg_redirect: Error redirect but could not capture TG data.' );
        }

        $return_url = self::get_return_url();

        // 1. Moodle redirect takes priority (user came from Moodle).
        if ( $moodle_url ) {
            if ( false !== strpos( $location, '/auth/edwiserbridge/' ) ) {
                kab_log( 'intercept_tg_redirect: EB SSO detected, rendering intermediate page. SSO=' . $location . ' Target=' . $moodle_url );
                self::render_sso_redirect_page( $location, $moodle_url );
                exit;
            }
            kab_log( 'intercept_tg_redirect: Moodle redirect: ' . $moodle_url );
            return $moodle_url;
        }

        // 2. Linking flow without Moodle URL: redirect to WP after EB SSO.
        if ( $is_linking && false !== strpos( $location, '/auth/edwiserbridge/' ) ) {
            $eb_page_id = get_option( 'eb_useraccount_page_id' );
            $redirect   = $eb_page_id ? get_permalink( $eb_page_id ) : home_url();
            kab_log( 'intercept_tg_redirect: Linking flow (no Moodle), redirecting to WP: ' . $redirect );
            return $redirect;
        }

        // 3. WordPress return URL.
        if ( $return_url ) {
            kab_log( 'intercept_tg_redirect: Returning to WP page: ' . $return_url );
            return $return_url;
        }

        kab_log( 'intercept_tg_redirect: No target URL, keeping original.' );
        return $location;
    }

    /**
     * Capture Telegram auth data from the current request.
     *
     * During the Telegram login callback, the auth data (id, first_name, etc.)
     * is available as query parameters. We capture it before the error redirect.
     *
     * @return array|false Telegram data array or false if not available.
     */
    private static function capture_telegram_data() {
        // phpcs:disable WordPress.Security.NonceVerification
        $id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';
        if ( empty( $id ) ) {
            kab_log( 'capture_telegram_data: No id in REQUEST. Keys=' . implode( ',', array_keys( $_REQUEST ?? array() ) ) );
            return false;
        }

        $data = array(
            'id'         => $id,
            'first_name' => isset( $_REQUEST['first_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['first_name'] ) ) : '',
            'last_name'  => isset( $_REQUEST['last_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['last_name'] ) ) : '',
            'username'   => isset( $_REQUEST['username'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['username'] ) ) : '',
        );
        // phpcs:enable WordPress.Security.NonceVerification

        kab_log( 'capture_telegram_data: Captured TG data: ' . wp_json_encode( $data ) );
        return $data;
    }

    /**
     * Handle requests with ?telegram_link=1 — show the account linking form.
     *
     * When a Telegram account is not linked to any WP user, we redirect here
     * instead of showing an error on wp-login.php. The user can enter their
     * existing WP credentials to link their Telegram account.
     */
    public static function handle_telegram_link_page() {
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( empty( $_GET['telegram_link'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $token   = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
        $tg_data = $token ? get_transient( 'kab_tg_link_' . $token ) : false;

        if ( ! $tg_data ) {
            kab_log( 'handle_telegram_link_page: Invalid or expired token.' );
            wp_redirect( home_url() );
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $moodle_url = isset( $_GET['moodle_redirect_to'] )
            ? esc_url_raw( wp_unslash( $_GET['moodle_redirect_to'] ) )
            : '';

        // phpcs:ignore WordPress.Security.NonceVerification
        $error = isset( $_GET['link_error'] )
            ? sanitize_text_field( wp_unslash( $_GET['link_error'] ) )
            : '';

        kab_log( 'handle_telegram_link_page: Rendering link page for TG user ' . $tg_data['id'] );
        self::render_telegram_link_page( $token, $tg_data, $moodle_url, $error );
        exit;
    }

    /**
     * Render the Telegram account linking page.
     *
     * @param string $token      Transient token for the Telegram data.
     * @param array  $tg_data    Telegram user data (id, first_name, username).
     * @param string $moodle_url Optional Moodle redirect URL.
     * @param string $error      Optional error message from a previous attempt.
     */
    private static function render_telegram_link_page( $token, $tg_data, $moodle_url, $error ) {
        $site_name = get_bloginfo( 'name' );
        $site_icon = get_site_icon_url( 64 );
        $nonce     = wp_create_nonce( 'kab_link_telegram' );

        $tg_display = '';
        if ( ! empty( $tg_data['username'] ) ) {
            $tg_display = '@' . esc_html( $tg_data['username'] );
        } elseif ( ! empty( $tg_data['first_name'] ) ) {
            $tg_display = esc_html( $tg_data['first_name'] );
            if ( ! empty( $tg_data['last_name'] ) ) {
                $tg_display .= ' ' . esc_html( $tg_data['last_name'] );
            }
        }

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $site_name ); ?> — Привязка Telegram</title>
            <style>
                body { background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
                .kab-link-box { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.13); padding: 32px 40px; max-width: 400px; width: 90%; }
                .kab-link-box .site-logo { text-align: center; margin-bottom: 16px; }
                .kab-link-box .site-logo img { width: 64px; height: 64px; border-radius: 8px; }
                .kab-link-box h1 { font-size: 20px; margin: 0 0 8px; text-align: center; }
                .kab-link-box .desc { color: #50575e; font-size: 14px; margin: 0 0 20px; text-align: center; line-height: 1.5; }
                .kab-link-box .tg-name { font-weight: 600; color: #2271b1; }
                .kab-link-box label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 4px; color: #1d2327; }
                .kab-link-box input[type="text"],
                .kab-link-box input[type="password"] { width: 100%; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px; box-sizing: border-box; margin-bottom: 16px; }
                .kab-link-box input:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
                .kab-link-box .submit-btn { width: 100%; padding: 10px; background: #2271b1; color: #fff; border: none; border-radius: 4px; font-size: 15px; font-weight: 600; cursor: pointer; }
                .kab-link-box .submit-btn:hover { background: #135e96; }
                .kab-link-box .error { background: #fcf0f1; border-left: 4px solid #d63638; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; color: #d63638; border-radius: 0 4px 4px 0; }
            </style>
        </head>
        <body>
            <div class="kab-link-box">
                <?php if ( $site_icon ) : ?>
                    <div class="site-logo"><img src="<?php echo esc_url( $site_icon ); ?>" alt=""></div>
                <?php endif; ?>
                <h1>Привязка Telegram</h1>
                <p class="desc">
                    Ваш Telegram аккаунт
                    <?php if ( $tg_display ) : ?>
                        (<span class="tg-name"><?php echo $tg_display; ?></span>)
                    <?php endif; ?>
                    не привязан ни к одному аккаунту на сайте.<br>
                    Введите логин и пароль, чтобы привязать Telegram и войти.
                </p>
                <?php if ( $error ) : ?>
                    <div class="error"><?php echo esc_html( $error ); ?></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                    <input type="hidden" name="telegram_link_submit" value="1">
                    <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
                    <input type="hidden" name="moodle_redirect_to" value="<?php echo esc_attr( $moodle_url ); ?>">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">

                    <label for="log">Имя пользователя или email</label>
                    <input type="text" name="log" id="log" autocomplete="username" required>

                    <label for="pwd">Пароль</label>
                    <input type="password" name="pwd" id="pwd" autocomplete="current-password" required>

                    <button type="submit" class="submit-btn">Привязать и войти</button>
                </form>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Process the Telegram linking form submission.
     *
     * Authenticates the user, links the Telegram account, logs them in,
     * and triggers EB SSO via do_action('wp_login').
     */
    public static function handle_telegram_link_submit() {
        if ( empty( $_POST['telegram_link_submit'] ) ) {
            return;
        }

        kab_log( 'handle_telegram_link_submit: Processing form submission.' );

        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        $nonce = wp_unslash( $_POST['_wpnonce'] ?? '' );

        // Verify nonce.
        if ( ! wp_verify_nonce( $nonce, 'kab_link_telegram' ) ) {
            kab_log( 'handle_telegram_link_submit: Nonce verification failed.' );
            wp_redirect( home_url() );
            exit;
        }

        // Get stored Telegram data.
        $tg_data = get_transient( 'kab_tg_link_' . $token );
        if ( ! $tg_data ) {
            kab_log( 'handle_telegram_link_submit: Invalid or expired token.' );
            self::redirect_link_error( $token, '', 'Сессия истекла. Попробуйте войти через Telegram снова.' );
        }

        $username   = sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) );
        $password   = wp_unslash( $_POST['pwd'] ?? '' );
        $moodle_url = esc_url_raw( wp_unslash( $_POST['moodle_redirect_to'] ?? '' ) );

        // Authenticate.
        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            $error_msg = $user->get_error_message();
            $error_msg = wp_strip_all_tags( $error_msg );
            kab_log( 'handle_telegram_link_submit: Auth failed: ' . $error_msg );
            self::redirect_link_error( $token, $moodle_url, $error_msg );
        }

        kab_log( 'handle_telegram_link_submit: Auth OK for user ' . $user->ID . '. Linking TG ID=' . $tg_data['id'] );

        // Link Telegram account.
        if ( defined( 'WPTELEGRAM_USER_ID_META_KEY' ) ) {
            update_user_meta( $user->ID, WPTELEGRAM_USER_ID_META_KEY, $tg_data['id'] );
        }
        if ( ! empty( $tg_data['username'] ) ) {
            $uname_key = defined( 'WPTELEGRAM_USERNAME_META_KEY' )
                ? WPTELEGRAM_USERNAME_META_KEY
                : 'wptelegram_username';
            update_user_meta( $user->ID, $uname_key, $tg_data['username'] );
        }
        if ( ! empty( $tg_data['first_name'] ) ) {
            update_user_meta( $user->ID, 'wptelegram_first_name', $tg_data['first_name'] );
        }

        // Clean up the transient.
        delete_transient( 'kab_tg_link_' . $token );

        // Log the user in and trigger EB SSO.
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        self::$is_linking_flow = true;
        if ( $moodle_url && self::is_allowed_moodle_url( $moodle_url ) ) {
            $_REQUEST['moodle_redirect_to'] = $moodle_url;
        }

        kab_log( 'handle_telegram_link_submit: Firing wp_login to trigger EB SSO.' );
        do_action( 'wp_login', $user->user_login, $user );

        // If EB SSO didn't redirect (exit), fall back to manual redirect.
        kab_log( 'handle_telegram_link_submit: EB SSO did not redirect. Falling back.' );
        if ( $moodle_url && self::is_allowed_moodle_url( $moodle_url ) ) {
            wp_redirect( $moodle_url );
        } else {
            $eb_page_id = get_option( 'eb_useraccount_page_id' );
            wp_redirect( $eb_page_id ? get_permalink( $eb_page_id ) : home_url() );
        }
        exit;
    }

    /**
     * Redirect back to the linking page with an error message.
     *
     * @param string $token      Transient token.
     * @param string $moodle_url Moodle redirect URL.
     * @param string $error      Error message.
     */
    private static function redirect_link_error( $token, $moodle_url, $error ) {
        $args = array(
            'telegram_link' => 1,
            'token'         => $token,
            'link_error'    => rawurlencode( $error ),
        );
        if ( $moodle_url ) {
            $args['moodle_redirect_to'] = rawurlencode( $moodle_url );
        }
        wp_redirect( add_query_arg( $args, home_url( '/' ) ) );
        exit;
    }

    /**
     * Render an intermediate page that establishes the Moodle session via a hidden
     * iframe (EB SSO) and then redirects the main window to the target Moodle page.
     *
     * This is necessary because Moodle's EB SSO login.php always redirects to /my/
     * (dashboard) and ignores the wantsurl parameter. By using an iframe, the SSO
     * sets the session cookie in the background, and then our JS redirect takes the
     * user directly to the correct page with an active session.
     *
     * Same-site context: kabacademy.com and edu.kabacademy.com share the same
     * registrable domain, so session cookies set in the iframe are available to
     * the main window navigation.
     *
     * @param string $sso_url    The EB SSO login URL (with login_id and veridy_code).
     * @param string $target_url The Moodle page the user should land on.
     */
    private static function render_sso_redirect_page( $sso_url, $target_url ) {
        // Clean any output buffers to ensure our HTML is sent directly.
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        $sso_escaped    = esc_url( $sso_url );
        $target_escaped = esc_url( $target_url );
        $target_js      = wp_json_encode( $target_url );

        ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Подключение к системе обучения…</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f0f0f1; color: #3c434a; }
        .loader { text-align: center; }
        .spinner { border: 4px solid #e0e0e0; border-top: 4px solid #2271b1; border-radius: 50%; width: 36px; height: 36px; animation: spin .8s linear infinite; margin: 0 auto 16px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        p { font-size: 15px; margin: 0; }
        .fallback { display: none; margin-top: 16px; }
        .fallback a { color: #2271b1; text-decoration: none; }
    </style>
</head>
<body>
    <div class="loader">
        <div class="spinner"></div>
        <p>Подключение к системе обучения…</p>
        <div class="fallback" id="fallback">
            <p><a href="<?php echo $target_escaped; ?>">Нажмите здесь, если страница не загружается</a></p>
        </div>
    </div>
    <iframe src="<?php echo $sso_escaped; ?>" style="display:none" id="sso"></iframe>
    <script>
    (function(){
        var done = false;
        var target = <?php echo $target_js; ?>;
        function go() {
            if (done) return;
            done = true;
            window.location.href = target;
        }
        var frame = document.getElementById('sso');
        frame.onload = function() {
            // SSO processed; small delay for cookie to finalize.
            setTimeout(go, 800);
        };
        frame.onerror = function() {
            go();
        };
        // Fallback: redirect after 6 seconds regardless.
        setTimeout(go, 6000);
        // Show manual link after 8 seconds if still here.
        setTimeout(function() {
            if (!done) document.getElementById('fallback').style.display = 'block';
        }, 8000);
    })();
    </script>
</body>
</html>
        <?php
        exit;
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
     * Get the return URL saved before Telegram login (the page the user was on).
     *
     * Checks multiple sources in order of reliability:
     * 1. redirect_to query parameter (set by WP Telegram Login "Current page")
     * 2. kab_return_to cookie (set by our save_return_url on course pages)
     * 3. kab_return_to transient (fallback when cookie doesn't survive)
     *
     * @return string|false Return URL or false.
     */
    private static function get_return_url() {
        $url = '';

        // 1. Check redirect_to from the request — WP Telegram Login's "Current page"
        //    option passes the originating page URL as this query parameter.
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! empty( $_REQUEST['redirect_to'] ) ) {
            $candidate = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
            // Only use it if it's a local (same-site) URL, not a Moodle/external URL.
            if ( $candidate && wp_validate_redirect( $candidate, false ) ) {
                $url = $candidate;
                kab_log( 'get_return_url: Found redirect_to in request: ' . $url );
            }
        }

        // 2. Check cookie set by KAB_Telegram_Widget::save_return_url().
        if ( empty( $url ) && ! empty( $_COOKIE['kab_return_to'] ) ) {
            $url = esc_url_raw( wp_unslash( $_COOKIE['kab_return_to'] ) );
            setcookie( 'kab_return_to', '', time() - 3600, '/', '', is_ssl(), true );
            kab_log( 'get_return_url: Found cookie kab_return_to: ' . $url );
        }

        // 3. Fallback: check transient.
        if ( empty( $url ) ) {
            $transient_key = 'kab_return_to_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
            $url           = get_transient( $transient_key );
            if ( $url ) {
                delete_transient( $transient_key );
                kab_log( 'get_return_url: Found transient: ' . $url );
            }
        }

        // Validate: only allow redirects to the same site.
        if ( $url && wp_validate_redirect( $url, false ) ) {
            kab_log( 'get_return_url: Validated return URL: ' . $url );
            return $url;
        }

        kab_log( 'get_return_url: No valid return URL found. REQUEST keys=' . implode( ',', array_keys( $_REQUEST ?? array() ) ) );
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
