<?php
/**
 * Provides Telegram login widget placement on custom locations:
 * - User profile page (link / unlink Telegram account)
 * - Edwiser Bridge course pages (login prompt for guests)
 * - [kab_telegram_login] shortcode for flexible placement
 */
class KAB_Telegram_Widget {

    public static function init() {
        add_action( 'show_user_profile', array( __CLASS__, 'show_telegram_link_section' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'show_telegram_link_section' ) );

        add_filter( 'the_content', array( __CLASS__, 'add_telegram_to_course_pages' ) );

        add_shortcode( 'kab_telegram_login', array( __CLASS__, 'telegram_login_shortcode' ) );
    }

    /**
     * Show Telegram link/unlink section on the user profile page.
     *
     * @param WP_User $user The user being viewed.
     */
    public static function show_telegram_link_section( $user ) {
        if ( ! defined( 'WPTELEGRAM_USER_ID_META_KEY' ) ) {
            return;
        }

        $telegram_id = get_user_meta( $user->ID, WPTELEGRAM_USER_ID_META_KEY, true );

        echo '<h2>' . esc_html__( 'Telegram Account' ) . '</h2>';
        echo '<table class="form-table"><tr>';
        echo '<th>' . esc_html__( 'Telegram Status' ) . '</th><td>';

        if ( ! empty( $telegram_id ) ) {
            $username_key = defined( 'WPTELEGRAM_USERNAME_META_KEY' )
                ? WPTELEGRAM_USERNAME_META_KEY
                : 'wptelegram_username';
            $telegram_username = get_user_meta( $user->ID, $username_key, true );

            echo '<span style="color:green;">&#10003; ' . esc_html__( 'Connected' ) . '</span>';
            if ( $telegram_username ) {
                echo ' (@' . esc_html( $telegram_username ) . ')';
            }
            echo '<br><br>';
            echo '<button type="button" class="button" id="kab-unlink-telegram">';
            echo esc_html__( 'Disconnect Telegram Account' );
            echo '</button>';
        } else {
            echo '<span style="color:#999;">' . esc_html__( 'Not connected' ) . '</span><br><br>';
            if ( function_exists( 'wptelegram_login' ) ) {
                wptelegram_login(
                    array(
                        'show_user_photo' => false,
                        'button_style'    => 'large',
                        'show_if_user_is' => 'logged_in',
                        'corner_radius'   => 10,
                    )
                );
            }
        }

        echo '</td></tr></table>';
    }

    /**
     * Add Telegram login prompt above Edwiser Bridge course content
     * for logged-out visitors.
     *
     * @param string $content Post content.
     * @return string Modified content.
     */
    public static function add_telegram_to_course_pages( $content ) {
        if ( is_user_logged_in() || ! is_singular( 'eb_course' ) ) {
            return $content;
        }

        if ( ! function_exists( 'wptelegram_login' ) ) {
            return $content;
        }

        ob_start();
        echo '<div class="kab-telegram-course-login" style="margin-bottom:24px;padding:16px;border:1px solid #ddd;border-radius:8px;text-align:center;">';
        echo '<p><strong>' . esc_html__( 'Already have an account?' ) . '</strong> ';
        echo esc_html__( 'Log in quickly with Telegram:' ) . '</p>';
        wptelegram_login(
            array(
                'show_user_photo' => false,
                'button_style'    => 'large',
                'show_if_user_is' => 'logged_out',
                'corner_radius'   => 10,
            )
        );
        echo '</div>';
        $button = ob_get_clean();

        return $button . $content;
    }

    /**
     * Shortcode [kab_telegram_login] for flexible placement.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function telegram_login_shortcode( $atts ) {
        if ( ! function_exists( 'wptelegram_login' ) ) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'button_style'    => 'large',
                'show_user_photo' => '0',
                'corner_radius'   => '10',
                'show_if_user_is' => 'logged_out',
                'message'         => 'Login with your Telegram account:',
            ),
            $atts
        );

        $html  = '<div class="kab-telegram-login-wrapper">';
        $html .= '<p>' . esc_html( $atts['message'] ) . '</p>';
        $html .= wptelegram_login(
            array(
                'show_user_photo' => (bool) $atts['show_user_photo'],
                'button_style'    => $atts['button_style'],
                'show_if_user_is' => $atts['show_if_user_is'],
                'corner_radius'   => (int) $atts['corner_radius'],
            ),
            false
        );
        $html .= '</div>';

        return $html;
    }
}
