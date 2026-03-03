<?php
/**
 * Ensures WordPress–Moodle account linking after Telegram login.
 *
 * When a user logs in via Telegram the standard wp_login action fires.
 * Edwiser Bridge SSO hooks into wp_login to initiate the Moodle SSO session.
 *
 * This class handles the edge case where a user has a WP account linked to
 * Telegram but does NOT yet have a Moodle account (moodle_user_id meta missing).
 * It triggers Edwiser Bridge user linking before SSO kicks in.
 */
class KAB_Moodle_Linker {

    public static function init() {
        // Priority 5 — run BEFORE Edwiser Bridge SSO (default priority 10).
        add_action( 'wp_login', array( __CLASS__, 'check_moodle_link_on_login' ), 5, 2 );

        // Also hook the plugin-specific action as a safety net.
        add_action( 'wptelegram_login_after_user_login', array( __CLASS__, 'ensure_moodle_link' ), 10, 2 );
    }

    /**
     * On wp_login, if this login originated from Telegram, verify the
     * Moodle link exists.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       Logged-in user object.
     */
    public static function check_moodle_link_on_login( $user_login, $user ) {
        if ( ! $user instanceof WP_User ) {
            return;
        }

        if ( ! defined( 'WPTELEGRAM_USER_ID_META_KEY' ) ) {
            return;
        }

        $telegram_id = get_user_meta( $user->ID, WPTELEGRAM_USER_ID_META_KEY, true );
        if ( empty( $telegram_id ) ) {
            return; // Not a Telegram-linked user.
        }

        // Check if request is a Telegram login action.
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! isset( $_REQUEST['action'] ) || 'wptelegram_login' !== $_REQUEST['action'] ) {
            return;
        }

        self::ensure_moodle_link( $user_login, $user );
    }

    /**
     * Verify the user has a moodle_user_id meta entry. If not, attempt
     * to create the link via Edwiser Bridge.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     */
    public static function ensure_moodle_link( $user_login, $user ) {
        if ( ! $user instanceof WP_User ) {
            return;
        }

        $moodle_user_id = get_user_meta( $user->ID, 'moodle_user_id', true );
        if ( ! empty( $moodle_user_id ) ) {
            return; // Already linked.
        }

        self::link_user_to_moodle( $user );
    }

    /**
     * Attempt to create a Moodle account link via Edwiser Bridge.
     *
     * @param WP_User $user WordPress user.
     */
    private static function link_user_to_moodle( $user ) {
        if ( ! function_exists( 'edwiser_bridge_instance' ) ) {
            error_log( 'KAB Telegram Bridge: Edwiser Bridge not available for Moodle linking.' );
            return;
        }

        try {
            $eb = edwiser_bridge_instance();
            if ( $eb && method_exists( $eb, 'user_manager' ) ) {
                $manager = $eb->user_manager();
                if ( $manager && method_exists( $manager, 'link_moodle_user' ) ) {
                    $manager->link_moodle_user( $user );
                    error_log(
                        sprintf(
                            'KAB Telegram Bridge: Linked WP user %d (%s) to Moodle after Telegram login.',
                            $user->ID,
                            $user->user_email
                        )
                    );
                }
            }
        } catch ( \Throwable $e ) {
            error_log( 'KAB Telegram Bridge: Failed to link user to Moodle — ' . $e->getMessage() );
        }
    }
}
