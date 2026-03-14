<?php
/**
 * SendPulse Telegram Bot integration.
 *
 * Subscribes users to the SendPulse Telegram bot when they connect
 * their Telegram account via WP Telegram Login. Uses WP Cron for
 * background processing so the user's redirect is not delayed.
 */
class KAB_SendPulse {

    public static function init() {
        // Background cron handler.
        add_action( 'kab_sendpulse_subscribe', array( __CLASS__, 'do_subscribe' ), 10, 2 );

        // Trigger subscription when user logs in via Telegram.
        add_action( 'wp_login', array( __CLASS__, 'on_telegram_login' ), 7, 2 );
    }

    /**
     * On Telegram login, schedule a background SendPulse subscription.
     *
     * Runs at priority 7 — before redirect_before_eb_sso (priority 8)
     * which may call exit.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     */
    public static function on_telegram_login( $user_login, $user ) {
        if ( ! kab_is_telegram_callback() ) {
            return;
        }

        if ( ! defined( 'KAB_SP_BOT_ID' ) || ! KAB_SP_BOT_ID ) {
            return;
        }

        // Skip if already subscribed.
        if ( get_user_meta( $user->ID, 'kab_sp_subscribed', true ) ) {
            return;
        }

        // Get Telegram ID — saved by WP TG Login before wp_login fires.
        $tg_id = '';
        if ( defined( 'WPTELEGRAM_USER_ID_META_KEY' ) ) {
            $tg_id = get_user_meta( $user->ID, WPTELEGRAM_USER_ID_META_KEY, true );
        }
        if ( ! $tg_id ) {
            // phpcs:ignore WordPress.Security.NonceVerification
            $tg_id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';
        }

        if ( ! $tg_id ) {
            return;
        }

        self::schedule( $user->ID, (string) $tg_id );
    }

    /**
     * Schedule a background subscription via WP Cron.
     *
     * @param int    $user_id WordPress user ID.
     * @param string $tg_id   Telegram user ID.
     */
    public static function schedule( $user_id, $tg_id ) {
        wp_schedule_single_event( time(), 'kab_sendpulse_subscribe', array( $user_id, $tg_id ) );
    }

    /**
     * Execute the SendPulse API call (runs in WP Cron context).
     *
     * @param int    $user_id WordPress user ID.
     * @param string $tg_id   Telegram user ID.
     */
    public static function do_subscribe( $user_id, $tg_id ) {
        $token = self::get_token();
        if ( ! $token ) {
            kab_log( 'SendPulse: Failed to get API token.' );
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $body = array(
            'bot_id'      => KAB_SP_BOT_ID,
            'telegram_id' => $tg_id,
            'variables'   => array(
                array( 'name' => 'email', 'value' => $user->user_email ),
                array( 'name' => 'name', 'value' => $user->first_name ?: $user->display_name ),
            ),
        );

        // Add phone if available (WooCommerce billing phone).
        $phone = get_user_meta( $user_id, 'billing_phone', true );
        if ( $phone ) {
            $body['variables'][] = array( 'name' => 'phone', 'value' => $phone );
        }

        /**
         * Filter the SendPulse subscriber data before sending.
         *
         * @param array $body    Request body.
         * @param int   $user_id WordPress user ID.
         * @param string $tg_id  Telegram user ID.
         */
        $body = apply_filters( 'kab_sendpulse_subscriber_data', $body, $user_id, $tg_id );

        $response = wp_remote_post( 'https://api.sendpulse.com/telegram/contacts', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ) );

        $code = wp_remote_retrieve_response_code( $response );

        if ( is_wp_error( $response ) ) {
            kab_log( 'SendPulse: API error — ' . $response->get_error_message() );
            return;
        }

        if ( $code >= 200 && $code < 300 ) {
            update_user_meta( $user_id, 'kab_sp_subscribed', 1 );
            kab_log( 'SendPulse: Subscribed user ' . $user_id . ' (tg_id=' . $tg_id . ').' );
        } else {
            kab_log( 'SendPulse: API returned ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
        }
    }

    /**
     * Get a SendPulse OAuth token (cached in transient for ~58 minutes).
     *
     * @return string|null Access token or null on failure.
     */
    public static function get_token() {
        $cached = get_transient( 'kab_sp_token' );
        if ( $cached ) {
            return $cached;
        }

        if ( ! defined( 'KAB_SP_CLIENT_ID' ) || ! defined( 'KAB_SP_CLIENT_SECRET' ) ) {
            return null;
        }

        $response = wp_remote_post( 'https://api.sendpulse.com/oauth/access_token', array(
            'body'    => array(
                'grant_type'    => 'client_credentials',
                'client_id'     => KAB_SP_CLIENT_ID,
                'client_secret' => KAB_SP_CLIENT_SECRET,
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $data  = json_decode( wp_remote_retrieve_body( $response ), true );
        $token = $data['access_token'] ?? null;

        if ( $token ) {
            set_transient( 'kab_sp_token', $token, 3500 );
        }

        return $token;
    }
}
