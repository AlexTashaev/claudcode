<?php
/**
 * Customizes the WordPress login page appearance and handles
 * AJAX operations for Telegram account linking/unlinking.
 *
 * Also implements smart redirect: when a user arrives from Moodle
 * (via the moodle_redirect_to parameter) they are sent back to Moodle
 * after successful Telegram login.
 *
 * Uses the Edwiser Bridge `eb_sso_login_url` filter to control the
 * post-SSO redirect destination, so the standard SSO round-trip
 * (WP → Moodle → WP → target) handles everything natively.
 */
class KAB_Login_Customizer {

    /** @var bool True during the link-Telegram-to-existing-account flow. */
    private static $is_linking_flow = false;

    /** @var string Moodle target URL to be used by eb_sso_login_url filter. */
    private static $pending_moodle_target = '';

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

        // Intercept Telegram callback for unlinked accounts BEFORE the plugin
        // processes it and shows its own error page.
        add_action( 'init', array( __CLASS__, 'intercept_unlinked_telegram_callback' ), 1 );

        // For WP-target logins: redirect BEFORE EB SSO at priority 8.
        // For Moodle-target logins: set $pending_moodle_target and let EB SSO run.
        add_action( 'wp_login', array( __CLASS__, 'redirect_before_eb_sso' ), 8, 2 );

        // Tell EB SSO where to redirect after the SSO round-trip completes.
        add_filter( 'eb_sso_login_url', array( __CLASS__, 'set_sso_redirect_url' ) );

        // BACKUP: Intercept wp_redirect in case EB SSO bypasses the filter.
        add_filter( 'wp_redirect', array( __CLASS__, 'intercept_telegram_login_redirect' ), 999 );

        // WP Telegram Login's own redirect filter.
        add_filter( 'wptelegram_login_user_redirect_to', array( __CLASS__, 'custom_redirect_after_login' ), 10, 2 );
    }

    /**
     * Handle requests with ?telegram_login=1 on the front-end.
     *
     * Renders a standalone Telegram login page that works regardless
     * of whether wp-login.php is accessible (security plugins may hide it).
     */
    public static function handle_telegram_login_page() {
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( empty( $_GET['telegram_login'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $moodle_url = isset( $_GET['moodle_redirect_to'] )
            ? esc_url_raw( wp_unslash( $_GET['moodle_redirect_to'] ) )
            : '';

        // Store moodle_redirect_to so it survives the Telegram auth callback.
        if ( $moodle_url && self::is_allowed_moodle_url( $moodle_url ) ) {
            setcookie( 'kab_moodle_redirect', $moodle_url, time() + 600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
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
     * PRIMARY redirect handler: runs on wp_login at priority 8.
     *
     * EB SSO hooks wp_login at priority 10. We use this hook to:
     * - For WP targets: redirect + exit BEFORE EB SSO runs (skip SSO round-trip).
     * - For Moodle targets: set $pending_moodle_target and let EB SSO run normally.
     *   The eb_sso_login_url filter will tell EB SSO to redirect to our target
     *   after the SSO round-trip completes.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     */
    public static function redirect_before_eb_sso( $user_login, $user ) {
        if ( ! kab_is_telegram_callback() ) {
            return;
        }

        kab_log( 'redirect_before_eb_sso: user=' . $user_login );

        // Moodle redirect: let EB SSO handle the SSO round-trip.
        // eb_sso_login_url filter will set the final destination.
        $moodle_url = self::get_moodle_redirect_url();
        if ( $moodle_url ) {
            self::$pending_moodle_target = $moodle_url;
            kab_log( 'redirect_before_eb_sso: Moodle target set via eb_sso_login_url: ' . $moodle_url );
            return;
        }

        // WordPress return URL — redirect immediately, skip EB SSO.
        $return_url = self::get_return_url();
        if ( $return_url ) {
            kab_log( 'redirect_before_eb_sso: WP redirect to ' . $return_url );
            setcookie( 'kab_after_tg_login', $return_url, time() + 120, '/', '', is_ssl(), true );
            remove_all_actions( 'wp_login' );
            wp_redirect( $return_url );
            exit;
        }

        // No return URL — try referer.
        $referer = wp_get_referer();
        if ( $referer && wp_validate_redirect( $referer, false ) ) {
            kab_log( 'redirect_before_eb_sso: Referer redirect to ' . $referer );
            setcookie( 'kab_after_tg_login', $referer, time() + 120, '/', '', is_ssl(), true );
            remove_all_actions( 'wp_login' );
            wp_redirect( $referer );
            exit;
        }

        kab_log( 'redirect_before_eb_sso: No target URL, deferring to EB SSO.' );
    }

    /**
     * Tell Edwiser Bridge SSO where to redirect after the SSO round-trip.
     *
     * EB SSO embeds this URL in the encrypted data sent to Moodle.
     * After the SSO round-trip (WP → Moodle → WP), the user is redirected
     * to this URL. This replaces the old iframe approach.
     *
     * @param string $url Default redirect URL from EB SSO.
     * @return string Modified redirect URL.
     */
    public static function set_sso_redirect_url( $url ) {
        if ( self::$pending_moodle_target ) {
            kab_log( 'set_sso_redirect_url: Overriding EB SSO target to ' . self::$pending_moodle_target );
            return self::$pending_moodle_target;
        }
        return $url;
    }

    /**
     * After successful Telegram login, redirect based on context.
     *
     * This is the WP Telegram Login plugin's own redirect filter.
     *
     * @param string  $redirect_to Current redirect URL.
     * @param WP_User $user        Logged-in user.
     * @return string Final redirect URL.
     */
    public static function custom_redirect_after_login( $redirect_to, $user = null ) {
        try {
            $moodle_url = self::get_moodle_redirect_url();
            if ( $moodle_url ) {
                // If EB SSO pending_moodle_target is already set, EB SSO will
                // handle the redirect through its own SSO round-trip.
                if ( self::$pending_moodle_target ) {
                    return $redirect_to;
                }
                // Otherwise (e.g. "connect Telegram" flow for already logged-in users),
                // route through the SSO handler to create a Moodle session.
                return home_url( '/?kab_goto_lesson=1' );
            }

            $return_url = self::get_return_url();
            if ( $return_url ) {
                return $return_url;
            }

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
     * BACKUP: Intercept wp_redirect during Telegram login callback.
     *
     * This is a safety net in case EB SSO bypasses the eb_sso_login_url filter
     * or the primary redirect handler doesn't catch a case.
     *
     * @param string $location The redirect URL.
     * @return string Modified redirect URL.
     */
    public static function intercept_telegram_login_redirect( $location ) {
        $is_tg_cb  = kab_is_telegram_callback();
        $is_linking = self::$is_linking_flow;

        if ( ! $is_tg_cb && ! $is_linking ) {
            return $location;
        }

        // Don't intercept redirects to our own pages.
        if ( false !== strpos( $location, 'telegram_link=1' ) ) {
            return $location;
        }

        // If pending_moodle_target is set, eb_sso_login_url already handles the
        // redirect destination. Let EB SSO's redirect pass through normally.
        if ( self::$pending_moodle_target && false !== strpos( $location, '/auth/edwiserbridge/' ) ) {
            kab_log( 'intercept_tg_redirect: EB SSO redirect with target set via filter, passing through.' );
            return $location;
        }

        // --- Unlinked Telegram account: redirect to linking page (fallback) ---
        if ( $is_tg_cb && false !== strpos( $location, 'wptelegram_login_error' ) ) {
            $tg_data = self::capture_telegram_data();
            if ( $tg_data ) {
                $token = wp_generate_password( 32, false );
                set_transient( 'kab_tg_link_' . $token, $tg_data, 600 );

                $moodle_url = self::get_moodle_redirect_url();
                $link_url   = home_url( '/?telegram_link=1&token=' . $token );
                if ( $moodle_url ) {
                    $link_url = add_query_arg( 'moodle_redirect_to', rawurlencode( $moodle_url ), $link_url );
                }
                kab_log( 'intercept_tg_redirect: Unlinked account, redirecting to link page.' );
                return $link_url;
            }
        }

        // Linking flow without Moodle URL: redirect to WP after EB SSO.
        if ( $is_linking && false !== strpos( $location, '/auth/edwiserbridge/' ) ) {
            $eb_page_id = get_option( 'eb_useraccount_page_id' );
            $redirect   = $eb_page_id ? get_permalink( $eb_page_id ) : home_url();
            kab_log( 'intercept_tg_redirect: Linking flow (no Moodle), redirecting to ' . $redirect );
            return $redirect;
        }

        // WordPress return URL.
        $return_url = self::get_return_url();
        if ( $return_url ) {
            kab_log( 'intercept_tg_redirect: Returning to ' . $return_url );
            return $return_url;
        }

        return $location;
    }

    /**
     * Intercept Telegram login callback when the account is not linked.
     *
     * Runs on init at priority 1 — BEFORE the WP Telegram Login plugin
     * processes the callback and shows its own error page (wp_die).
     */
    public static function intercept_unlinked_telegram_callback() {
        if ( ! kab_is_telegram_callback() ) {
            return;
        }

        // If user is already logged in, this is a "connect Telegram" flow
        // (e.g. from the Thank You page). Let WP Telegram Login handle
        // the linking natively — no need for our custom linking page.
        if ( is_user_logged_in() ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $tg_id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';
        if ( empty( $tg_id ) ) {
            return;
        }

        if ( ! defined( 'WPTELEGRAM_USER_ID_META_KEY' ) ) {
            return;
        }

        // Check if this Telegram ID is already linked to a WP user.
        $linked_users = get_users(
            array(
                'meta_key'   => WPTELEGRAM_USER_ID_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
                'meta_value' => $tg_id, // phpcs:ignore WordPress.DB.SlowDBQuery
                'number'     => 1,
                'fields'     => 'ID',
            )
        );

        if ( ! empty( $linked_users ) ) {
            return; // User found, let the plugin handle login normally.
        }

        kab_log( 'intercept_unlinked_tg: TG ID ' . $tg_id . ' NOT linked. Redirecting to link page.' );

        $tg_data = self::capture_telegram_data();
        if ( ! $tg_data ) {
            return;
        }

        $token = wp_generate_password( 32, false );
        set_transient( 'kab_tg_link_' . $token, $tg_data, 600 );

        $moodle_url = self::get_moodle_redirect_url();

        $link_url = home_url( '/?telegram_link=1&token=' . $token );
        if ( $moodle_url ) {
            $link_url = add_query_arg( 'moodle_redirect_to', rawurlencode( $moodle_url ), $link_url );
        }

        wp_redirect( $link_url );
        exit;
    }

    /**
     * Capture Telegram auth data from the current request.
     *
     * @return array|false Telegram data array or false if not available.
     */
    private static function capture_telegram_data() {
        // phpcs:disable WordPress.Security.NonceVerification
        $id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';
        if ( empty( $id ) ) {
            return false;
        }

        return array(
            'id'         => $id,
            'first_name' => isset( $_REQUEST['first_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['first_name'] ) ) : '',
            'last_name'  => isset( $_REQUEST['last_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['last_name'] ) ) : '',
            'username'   => isset( $_REQUEST['username'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['username'] ) ) : '',
        );
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Handle requests with ?telegram_link=1 — show the account linking form.
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

        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        $nonce = wp_unslash( $_POST['_wpnonce'] ?? '' );

        if ( ! wp_verify_nonce( $nonce, 'kab_link_telegram' ) ) {
            wp_redirect( home_url() );
            exit;
        }

        $tg_data = get_transient( 'kab_tg_link_' . $token );
        if ( ! $tg_data ) {
            self::redirect_link_error( $token, '', 'Сессия истекла. Попробуйте войти через Telegram снова.' );
        }

        $username   = sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) );
        $password   = wp_unslash( $_POST['pwd'] ?? '' );
        $moodle_url = esc_url_raw( wp_unslash( $_POST['moodle_redirect_to'] ?? '' ) );

        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            $error_msg = wp_strip_all_tags( $user->get_error_message() );
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

        delete_transient( 'kab_tg_link_' . $token );

        // Log the user in.
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        // Set up redirect targets for EB SSO.
        self::$is_linking_flow = true;
        if ( $moodle_url && self::is_allowed_moodle_url( $moodle_url ) ) {
            self::$pending_moodle_target = $moodle_url;
            $_REQUEST['moodle_redirect_to'] = $moodle_url;
        }

        kab_log( 'handle_telegram_link_submit: Firing wp_login to trigger EB SSO.' );
        do_action( 'wp_login', $user->user_login, $user );

        // If EB SSO didn't redirect (exit), fall back to manual redirect.
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
            unset( $_COOKIE['kab_moodle_redirect'] );
        }

        // 3. Fallback: check the transient.
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
     * Get the return URL saved before Telegram login.
     *
     * @return string|false Return URL or false.
     */
    private static function get_return_url() {
        $url = '';

        // 1. Check redirect_to from the request.
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! empty( $_REQUEST['redirect_to'] ) ) {
            $candidate = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
            if ( $candidate && wp_validate_redirect( $candidate, false ) ) {
                $url = $candidate;
            }
        }

        // 2. Check cookie set by KAB_Telegram_Widget::save_return_url().
        if ( empty( $url ) && ! empty( $_COOKIE['kab_return_to'] ) ) {
            $url = esc_url_raw( wp_unslash( $_COOKIE['kab_return_to'] ) );
            setcookie( 'kab_return_to', '', time() - 3600, '/', '', is_ssl(), true );
            unset( $_COOKIE['kab_return_to'] );
        }

        // 3. Fallback: check transient.
        if ( empty( $url ) ) {
            $transient_key = 'kab_return_to_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
            $url           = get_transient( $transient_key );
            if ( $url ) {
                delete_transient( $transient_key );
            }
        }

        if ( $url && wp_validate_redirect( $url, false ) ) {
            return $url;
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
