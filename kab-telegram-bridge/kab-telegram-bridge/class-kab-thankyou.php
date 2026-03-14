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

        $lesson_url = defined( 'KAB_FIRST_LESSON_URL' ) ? KAB_FIRST_LESSON_URL : '';
        if ( ! $lesson_url ) {
            return;
        }

        // Don't overwrite if already set in this session.
        if ( ! empty( $_COOKIE['kab_moodle_redirect'] ) ) {
            return;
        }

        setcookie( 'kab_moodle_redirect', $lesson_url, time() + 600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        // Make available in the same request (for the shortcode).
        $_COOKIE['kab_moodle_redirect'] = $lesson_url;
    }

    /**
     * [kab_thankyou_block] shortcode.
     *
     * Attributes:
     *   lesson_url — override the default KAB_FIRST_LESSON_URL for this page.
     *
     * Two states:
     *   1. Telegram NOT connected → show connect CTA + benefits list.
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
            'lesson_url' => defined( 'KAB_FIRST_LESSON_URL' ) ? KAB_FIRST_LESSON_URL : '',
        ), $atts, 'kab_thankyou_block' );

        $lesson_url = $atts['lesson_url'];
        $user       = wp_get_current_user();
        $name       = $user->first_name ?: $user->display_name;
        $tg_id      = '';

        if ( defined( 'WPTELEGRAM_USER_ID_META_KEY' ) ) {
            $tg_id = get_user_meta( $user->ID, WPTELEGRAM_USER_ID_META_KEY, true );
        }

        ob_start();
        ?>
        <div class="kab-tq">
            <div class="kab-tq-icon">🎉</div>
            <h2 class="kab-tq-title">
                <?php
                echo $name
                    ? 'Добро пожаловать, ' . esc_html( $name ) . '!'
                    : 'Вы успешно записаны!';
                ?>
            </h2>

            <?php if ( ! $tg_id ) : ?>
                <!-- Telegram ещё не привязан — CTA -->
                <p class="kab-tq-sub">
                    Нажмите одну кнопку — войдёте через Telegram<br>
                    и сразу окажетесь на первом уроке
                </p>

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

                <p class="kab-tq-bonus">
                    ✔ Войдёте на сайт одним кликом в следующий раз<br>
                    ✔ Будем присылать расписание и материалы в Telegram<br>
                    ✔ Сразу попадёте на первый урок
                </p>

                <?php if ( $lesson_url ) : ?>
                    <p class="kab-tq-skip">
                        <a href="<?php echo esc_url( $lesson_url ); ?>">
                            Пропустить и перейти к уроку →
                        </a>
                    </p>
                <?php endif; ?>

            <?php else : ?>
                <!-- Telegram уже привязан — прямая кнопка на урок -->
                <p class="kab-tq-sub">Telegram подключён. Первый урок уже ждёт вас:</p>

                <?php if ( $lesson_url ) : ?>
                    <a href="<?php echo esc_url( $lesson_url ); ?>" class="kab-btn-lesson">
                        🎓 Начать первый урок →
                    </a>
                <?php endif; ?>

                <p class="kab-tq-bonus">
                    ✔ Будем присылать расписание и материалы в Telegram
                </p>
            <?php endif; ?>
        </div>

        <style>
        .kab-tq           { text-align:center; max-width:500px; margin:0 auto; padding:10px 0; }
        .kab-tq-icon      { font-size:52px; margin-bottom:10px; }
        .kab-tq-title     { font-size:24px; font-weight:800; margin:0 0 10px; }
        .kab-tq-sub       { font-size:16px; color:#444; margin:0 0 22px; line-height:1.6; }
        .kab-tg-login-wrap { display:inline-block; transform:scale(1.25); margin:10px 0 18px; }
        .kab-tq-bonus     { font-size:14px; color:#555; line-height:2; margin:18px 0 12px; text-align:left; display:inline-block; }
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
