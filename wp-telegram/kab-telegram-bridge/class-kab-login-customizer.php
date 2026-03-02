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
        add_filter( 'wptelegram_login_user_redirect_to', array( __CLASS__, 'custom_redirect_after_login' ), 10, 2 );
        add_action( 'wp_ajax_kab_unlink_telegram', array( __CLASS__, 'ajax_unlink_telegram' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_profile_scripts' ) );

        // Persist the moodle_redirect_to param through the login form.
        add_action( 'login_form', array( __CLASS__, 'persist_moodle_redirect' ) );
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
    public static function custom_redirect_after_login( $redirect_to, $user ) {
        // If user came from Moodle, send them back.
        // phpcs:ignore WordPress.Security.NonceVerification
        $moodle_url = isset( $_REQUEST['moodle_redirect_to'] )
            ? esc_url_raw( wp_unslash( $_REQUEST['moodle_redirect_to'] ) )
            : '';

        if ( $moodle_url && self::is_allowed_moodle_url( $moodle_url ) ) {
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
