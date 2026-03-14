<?php
/**
 * Admin settings page for KAB Telegram Bridge.
 *
 * Adds a settings page under Settings → KAB Telegram Bridge
 * with fields for SendPulse credentials, bot ID, and lesson URL.
 *
 * Values stored in wp_options as 'kab_telegram_bridge_settings'.
 * Constants in wp-config.php take priority over DB values.
 */
class KAB_Settings {

    const OPTION_KEY = 'kab_telegram_bridge_settings';
    const PAGE_SLUG  = 'kab-telegram-bridge';

    /** @var array|null Cached settings. */
    private static $settings = null;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_filter( 'plugin_action_links_kab-telegram-bridge/kab-telegram-bridge.php', array( __CLASS__, 'add_settings_link' ) );
    }

    /**
     * Get a single setting value.
     *
     * Constants (KAB_SP_CLIENT_ID, etc.) take priority over DB values.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get( $key, $default = '' ) {
        // Map setting keys to constant names.
        $constant_map = array(
            'sp_client_id'     => 'KAB_SP_CLIENT_ID',
            'sp_client_secret' => 'KAB_SP_CLIENT_SECRET',
            'sp_bot_id'        => 'KAB_SP_BOT_ID',
            'first_lesson_url' => 'KAB_FIRST_LESSON_URL',
        );

        // Constant takes priority.
        if ( isset( $constant_map[ $key ] ) && defined( $constant_map[ $key ] ) && constant( $constant_map[ $key ] ) ) {
            return constant( $constant_map[ $key ] );
        }

        if ( null === self::$settings ) {
            self::$settings = get_option( self::OPTION_KEY, array() );
        }

        return isset( self::$settings[ $key ] ) && '' !== self::$settings[ $key ]
            ? self::$settings[ $key ]
            : $default;
    }

    /**
     * Add submenu page under Settings.
     */
    public static function add_menu() {
        add_options_page(
            'KAB Telegram Bridge',
            'KAB Telegram Bridge',
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Add "Settings" link on the Plugins page.
     *
     * @param array $links Existing links.
     * @return array
     */
    public static function add_settings_link( $links ) {
        $url  = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
        $link = '<a href="' . esc_url( $url ) . '">Настройки</a>';
        array_unshift( $links, $link );
        return $links;
    }

    /**
     * Register settings, sections, and fields.
     */
    public static function register_settings() {
        register_setting( self::PAGE_SLUG, self::OPTION_KEY, array(
            'sanitize_callback' => array( __CLASS__, 'sanitize' ),
        ) );

        // --- SendPulse section ---
        add_settings_section(
            'kab_sp_section',
            'SendPulse',
            function () {
                echo '<p>Данные для подключения к Telegram-боту через SendPulse API. '
                    . 'Получить можно в <a href="https://login.sendpulse.com/account/api/" target="_blank">настройках API</a>.</p>';
            },
            self::PAGE_SLUG
        );

        self::add_field( 'sp_client_id', 'Client ID', 'kab_sp_section', 'KAB_SP_CLIENT_ID' );
        self::add_field( 'sp_client_secret', 'Client Secret', 'kab_sp_section', 'KAB_SP_CLIENT_SECRET', 'password' );
        self::add_field( 'sp_bot_id', 'Bot ID', 'kab_sp_section', 'KAB_SP_BOT_ID' );

        // --- Lesson section ---
        add_settings_section(
            'kab_lesson_section',
            'Первый урок',
            function () {
                echo '<p>URL первого урока в Moodle. После подключения Telegram на странице «Спасибо» '
                    . 'студент будет перенаправлен на этот урок.</p>';
            },
            self::PAGE_SLUG
        );

        self::add_field( 'first_lesson_url', 'URL урока', 'kab_lesson_section', 'KAB_FIRST_LESSON_URL', 'url' );
    }

    /**
     * Add a single settings field.
     *
     * @param string $key           Setting key.
     * @param string $label         Field label.
     * @param string $section       Section ID.
     * @param string $constant_name Corresponding constant name.
     * @param string $type          Input type (text, password, url).
     */
    private static function add_field( $key, $label, $section, $constant_name, $type = 'text' ) {
        add_settings_field(
            'kab_' . $key,
            $label,
            function () use ( $key, $constant_name, $type ) {
                $value         = self::get( $key );
                $from_constant = defined( $constant_name ) && constant( $constant_name );

                if ( $from_constant ) {
                    // Show masked value when set via constant.
                    $display = 'password' === $type
                        ? '••••••••'
                        : esc_attr( $value );
                    echo '<input type="text" value="' . $display . '" class="regular-text" disabled> ';
                    echo '<span class="description">Задано через константу <code>' . esc_html( $constant_name ) . '</code></span>';
                } else {
                    $input_value = esc_attr( $value );
                    echo '<input type="' . esc_attr( $type ) . '" '
                        . 'name="' . self::OPTION_KEY . '[' . esc_attr( $key ) . ']" '
                        . 'value="' . $input_value . '" '
                        . 'class="regular-text">';
                }
            },
            self::PAGE_SLUG,
            $section
        );
    }

    /**
     * Sanitize settings before saving.
     *
     * @param array $input Raw input.
     * @return array Sanitized values.
     */
    public static function sanitize( $input ) {
        $clean = array();

        if ( isset( $input['sp_client_id'] ) ) {
            $clean['sp_client_id'] = sanitize_text_field( $input['sp_client_id'] );
        }
        if ( isset( $input['sp_client_secret'] ) ) {
            $clean['sp_client_secret'] = sanitize_text_field( $input['sp_client_secret'] );
        }
        if ( isset( $input['sp_bot_id'] ) ) {
            $clean['sp_bot_id'] = sanitize_text_field( $input['sp_bot_id'] );
        }
        if ( isset( $input['first_lesson_url'] ) ) {
            $clean['first_lesson_url'] = esc_url_raw( $input['first_lesson_url'] );
        }

        // Clear settings cache.
        self::$settings = null;

        return $clean;
    }

    /**
     * Render the settings page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>KAB Telegram Bridge</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::PAGE_SLUG );
                do_settings_sections( self::PAGE_SLUG );
                submit_button( 'Сохранить настройки' );
                ?>
            </form>

            <hr>
            <h2>Шорткод для страницы «Спасибо»</h2>
            <p>Вставьте в Elementor (виджет Shortcode) на Thank You странице CartFlows:</p>
            <pre style="background:#f0f0f1;padding:12px;display:inline-block;border-radius:4px;"><code>[kab_thankyou_block]</code></pre>
            <p>Или с кастомным URL урока для конкретной страницы:</p>
            <pre style="background:#f0f0f1;padding:12px;display:inline-block;border-radius:4px;"><code>[kab_thankyou_block lesson_url="https://edu.kabacademy.com/mod/lesson/view.php?id=123"]</code></pre>

            <?php self::render_status_section(); ?>
        </div>
        <?php
    }

    /**
     * Show connection status for SendPulse.
     */
    private static function render_status_section() {
        $client_id = self::get( 'sp_client_id' );
        $secret    = self::get( 'sp_client_secret' );
        $bot_id    = self::get( 'sp_bot_id' );
        $lesson    = self::get( 'first_lesson_url' );

        echo '<hr><h2>Статус</h2><table class="widefat" style="max-width:600px"><tbody>';

        // SendPulse credentials.
        $sp_ok = $client_id && $secret;
        self::status_row( 'SendPulse API', $sp_ok ? 'Настроено' : 'Не задан Client ID / Secret', $sp_ok );

        // Bot ID.
        self::status_row( 'SendPulse Bot ID', $bot_id ? $bot_id : 'Не задан', (bool) $bot_id );

        // Lesson URL.
        self::status_row( 'URL первого урока', $lesson ? $lesson : 'Не задан', (bool) $lesson );

        // Token test (only if credentials are set).
        if ( $sp_ok ) {
            $token = KAB_SendPulse::get_token();
            self::status_row( 'SendPulse токен', $token ? 'Получен' : 'Ошибка получения', (bool) $token );
        }

        echo '</tbody></table>';
    }

    /**
     * Render a single status row.
     *
     * @param string $label  Row label.
     * @param string $value  Status text.
     * @param bool   $is_ok  Whether the status is good.
     */
    private static function status_row( $label, $value, $is_ok ) {
        $icon  = $is_ok ? '✅' : '❌';
        $color = $is_ok ? '#00a32a' : '#d63638';
        echo '<tr><td style="padding:8px 12px;font-weight:600">' . esc_html( $label ) . '</td>';
        echo '<td style="padding:8px 12px;color:' . $color . '">' . $icon . ' ' . esc_html( $value ) . '</td></tr>';
    }
}
