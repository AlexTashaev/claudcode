<?php
/**
 * Thank You page integration.
 *
 * On CartFlows Thank You pages:
 * 1. Sets a cookie so that Telegram login redirects to the first lesson.
 * 2. Renders a shortcode with Telegram connect CTA or direct lesson link.
 *
 * The cookie reuses the existing kab_moodle_redirect mechanism:
 * when the user clicks "Log in via Telegram", the Telegram callback
 * reads the cookie → eb_sso_login_url filter sets the lesson as the
 * post-SSO destination → user lands on the lesson with an active
 * Moodle session.
 */
class KAB_ThankYou {

    public static function init() {
        add_shortcode( 'kab_thankyou_block', array( __CLASS__, 'shortcode' ) );
        add_action( 'template_redirect', array( __CLASS__, 'set_lesson_cookie' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_lesson_redirect' ) );
    }

    /**
     * Set kab_moodle_redirect cookie on Thank You pages.
     *
     * This cookie is read by KAB_Login_Customizer::get_moodle_redirect_url()
     * during the Telegram login callback, triggering the SSO redirect
     * to the first lesson.
     */
    public static function set_lesson_cookie() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ( false === strpos( $uri, 'thankyou' ) && false === strpos( $uri, 'thank-you' ) ) {
            return;
        }

        $lesson_url = KAB_Settings::get( 'first_lesson_url' );
        if ( ! $lesson_url ) {
            return;
        }

        // Don't overwrite if already set in this session.
        if ( ! empty( $_COOKIE['kab_moodle_redirect'] ) ) {
            return;
        }

        setcookie( 'kab_moodle_redirect', $lesson_url, time() + 600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        $_COOKIE['kab_moodle_redirect'] = $lesson_url;
    }

    /**
     * Handle ?kab_goto_lesson=1 — redirect to lesson through EB SSO.
     *
     * When a logged-in WP user clicks "skip" or finishes Telegram connect
     * on the Thank You page, they need a Moodle session. This handler
     * generates the EB SSO URL and redirects through Moodle's SSO endpoint.
     */
    public static function handle_lesson_redirect() {
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( empty( $_GET['kab_goto_lesson'] ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            wp_redirect( wp_login_url() );
            exit;
        }

        // Read lesson URL from cookie (set on Thank You page) or fall back to settings.
        $lesson_url = '';
        if ( ! empty( $_COOKIE['kab_moodle_redirect'] ) ) {
            $lesson_url = esc_url_raw( wp_unslash( $_COOKIE['kab_moodle_redirect'] ) );
        }
        if ( ! $lesson_url ) {
            $lesson_url = KAB_Settings::get( 'first_lesson_url' );
        }
        if ( ! $lesson_url ) {
            wp_redirect( home_url() );
            exit;
        }

        $user = wp_get_current_user();
        kab_log( 'handle_lesson_redirect: user=' . $user->ID . ' email=' . $user->user_email . ' lesson=' . $lesson_url );

        // Store lesson URL in transient for the global eb_sso_login_url filter.
        set_transient( 'kab_lesson_sso_' . $user->ID, $lesson_url, 300 );

        // --- Approach 1: Generate EB SSO URL directly ---
        $sso_url = self::generate_eb_sso_url( $user, $lesson_url );
        if ( $sso_url ) {
            kab_log( 'handle_lesson_redirect: Generated EB SSO URL, redirecting.' );
            wp_redirect( $sso_url );
            exit;
        }

        // --- Approach 2: Try do_action('wp_login') to trigger EB SSO hooks ---
        if ( function_exists( 'edwiser_bridge_instance' ) ) {
            add_filter( 'eb_sso_login_url', function () use ( $lesson_url ) {
                return $lesson_url;
            }, 999 );

            // Remove our own hooks to avoid interference.
            remove_action( 'wp_login', array( 'KAB_Login_Customizer', 'redirect_before_eb_sso' ), 8 );
            remove_action( 'wp_login', array( 'KAB_Moodle_Linker', 'check_moodle_link_on_login' ), 5 );
            remove_filter( 'wp_redirect', array( 'KAB_Login_Customizer', 'intercept_telegram_login_redirect' ), 999 );

            // Log registered wp_login hooks for debugging.
            global $wp_filter;
            if ( isset( $wp_filter['wp_login'] ) ) {
                foreach ( $wp_filter['wp_login']->callbacks as $pri => $hooks ) {
                    foreach ( $hooks as $id => $_ ) {
                        kab_log( "handle_lesson_redirect: wp_login hook[$pri]: $id" );
                    }
                }
            }

            kab_log( 'handle_lesson_redirect: Firing do_action(wp_login).' );
            do_action( 'wp_login', $user->user_login, $user );
            kab_log( 'handle_lesson_redirect: do_action(wp_login) did not redirect.' );
        }

        // --- Approach 3: Fall back to direct Moodle URL ---
        kab_log( 'handle_lesson_redirect: All SSO approaches failed. Falling back to direct URL: ' . $lesson_url );
        wp_redirect( $lesson_url );
        exit;
    }

    /**
     * Try to generate an EB SSO URL by encrypting user data with the shared secret key.
     *
     * Reads Moodle URL and SSO secret from Edwiser Bridge options, encrypts
     * user data in the format expected by Moodle's auth/edwiserbridge/sso.php,
     * and returns the full SSO URL.
     *
     * @param WP_User $user        WordPress user.
     * @param string  $redirect_to Lesson URL to redirect to after Moodle login.
     * @return string|false SSO URL or false on failure.
     */
    private static function generate_eb_sso_url( $user, $redirect_to ) {
        // --- Find Moodle base URL ---
        $moodle_url = '';
        $connection = get_option( 'eb_connection' );
        if ( is_array( $connection ) && ! empty( $connection['eb_url'] ) ) {
            $moodle_url = $connection['eb_url'];
        }
        if ( ! $moodle_url ) {
            $moodle_url = get_option( 'eb_url', '' );
        }
        if ( ! $moodle_url ) {
            kab_log( 'generate_eb_sso_url: No Moodle URL found in eb_connection/eb_url options.' );
            return false;
        }

        // --- Find SSO secret key ---
        $sso_key = '';

        // EB SSO stores settings in different option names depending on version.
        $key_options = array(
            'eb_sso_secret_key',
            'wdm_eb_sso_secret_key',
        );
        foreach ( $key_options as $opt ) {
            $val = get_option( $opt, '' );
            if ( $val ) {
                $sso_key = $val;
                break;
            }
        }

        // Also check array-style settings.
        if ( ! $sso_key ) {
            $sso_settings = get_option( 'eb_sso_settings' );
            if ( is_array( $sso_settings ) ) {
                $sso_key = $sso_settings['eb_sso_secret_key']
                    ?? $sso_settings['secret_key']
                    ?? '';
            }
        }

        if ( ! $sso_key ) {
            kab_log( 'generate_eb_sso_url: No SSO secret key found. Checked: '
                . implode( ', ', $key_options ) . ', eb_sso_settings' );

            // Log all EB-related options for debugging.
            global $wpdb;
            $eb_opts = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'eb_%' OR option_name LIKE 'wdm_%'"
            );
            kab_log( 'generate_eb_sso_url: EB-related options: ' . implode( ', ', $eb_opts ) );
            return false;
        }

        kab_log( 'generate_eb_sso_url: Found Moodle URL=' . $moodle_url . ' SSO key length=' . strlen( $sso_key ) );

        // --- Build user data payload ---
        $data = array(
            'username'    => $user->user_login,
            'email'       => $user->user_email,
            'firstname'   => $user->first_name ?: $user->display_name,
            'lastname'    => $user->last_name ?: '',
            'redirect_to' => $redirect_to,
        );

        $json = wp_json_encode( $data );

        // --- Encrypt (AES-128-ECB — standard EB SSO format) ---
        $encrypted = openssl_encrypt( $json, 'AES-128-ECB', $sso_key, 0 );
        if ( ! $encrypted ) {
            kab_log( 'generate_eb_sso_url: AES-128-ECB encryption failed.' );
            return false;
        }

        $sso_url = rtrim( $moodle_url, '/' ) . '/auth/edwiserbridge/sso.php?data=' . rawurlencode( $encrypted );
        kab_log( 'generate_eb_sso_url: Generated URL (length=' . strlen( $sso_url ) . ')' );
        return $sso_url;
    }

    /**
     * [kab_thankyou_block] shortcode.
     *
     * Attributes:
     *   lesson_url — override the default first lesson URL for this page.
     *
     * Two states:
     *   1. Telegram NOT connected → show Telegram login button + skip link.
     *   2. Telegram connected → show "Start lesson" button.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML.
     */
    public static function shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $atts = shortcode_atts( array(
            'lesson_url' => KAB_Settings::get( 'first_lesson_url' ),
        ), $atts, 'kab_thankyou_block' );

        $lesson_url = $atts['lesson_url'];
        $user       = wp_get_current_user();
        $name       = $user->first_name ?: $user->display_name;
        $tg_id      = '';

        if ( defined( 'WPTELEGRAM_USER_ID_META_KEY' ) ) {
            $tg_id = get_user_meta( $user->ID, WPTELEGRAM_USER_ID_META_KEY, true );
        }

        // SSO-aware lesson link (goes through EB SSO to create Moodle session).
        $lesson_sso_url = $lesson_url
            ? home_url( '/?kab_goto_lesson=1' )
            : '';

        ob_start();
        ?>
        <div class="kab-tq">
            <?php if ( ! $tg_id ) : ?>
                <!-- Telegram not connected — show login button -->
                <div class="kab-tg-login-wrap">
                    <?php
                    if ( function_exists( 'wptelegram_login' ) ) {
                        wptelegram_login( array(
                            'button_style'    => 'large',
                            'show_user_photo' => true,
                            'show_if_user_is' => 'logged_in',
                        ) );
                    }
                    ?>
                </div>

                <?php if ( $lesson_sso_url ) : ?>
                    <p class="kab-tq-skip">
                        <a href="<?php echo esc_url( $lesson_sso_url ); ?>">
                            Пропустить и перейти к уроку →
                        </a>
                    </p>
                <?php endif; ?>

            <?php else : ?>
                <!-- Telegram connected — direct lesson button -->
                <?php if ( $lesson_sso_url ) : ?>
                    <a href="<?php echo esc_url( $lesson_sso_url ); ?>" class="kab-btn-lesson">
                        🎓 Начать первый урок →
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <style>
        .kab-tq           { text-align:center; max-width:500px; margin:0 auto; padding:10px 0; }
        .kab-tg-login-wrap { display:inline-block; transform:scale(1.25); margin:10px 0 18px; }
        .kab-tq-skip      { margin-top:10px; }
        .kab-tq-skip a    { font-size:13px; color:#aaa; text-decoration:underline; }
        .kab-tq-skip a:hover { color:#666; }
        .kab-btn-lesson {
            display:inline-block; background:#1a6ef0; color:#fff !important;
            text-decoration:none; padding:18px 52px; border-radius:12px;
            font-size:20px; font-weight:700;
            box-shadow:0 6px 24px rgba(26,110,240,.35);
            transition:transform .15s, box-shadow .15s;
        }
        .kab-btn-lesson:hover { transform:translateY(-2px); box-shadow:0 10px 28px rgba(26,110,240,.45); }
        </style>
        <?php
        return ob_get_clean();
    }
}
