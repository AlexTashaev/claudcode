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
        // This action passes only 1 arg (WP_User), so we accept 1.
        add_action( 'wptelegram_login_after_user_login', array( __CLASS__, 'ensure_moodle_link' ), 10, 1 );
    }

    /**
     * On wp_login, if this login originated from Telegram, verify the
     * Moodle link exists.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       Logged-in user object.
     */
    public static function check_moodle_link_on_login( $user_login, $user ) {
        try {
            kab_log( 'check_moodle_link_on_login called for: ' . $user_login );

            if ( ! $user instanceof WP_User ) {
                kab_log( 'check_moodle_link_on_login: $user is not WP_User, skipping.' );
                return;
            }

            if ( ! defined( 'WPTELEGRAM_USER_ID_META_KEY' ) ) {
                kab_log( 'check_moodle_link_on_login: WPTELEGRAM_USER_ID_META_KEY not defined, skipping.' );
                return;
            }

            $telegram_id = get_user_meta( $user->ID, WPTELEGRAM_USER_ID_META_KEY, true );
            if ( empty( $telegram_id ) ) {
                kab_log( 'check_moodle_link_on_login: No Telegram ID for user, skipping.' );
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification
            if ( ! isset( $_REQUEST['action'] ) || 'wptelegram_login' !== $_REQUEST['action'] ) {
                kab_log( 'check_moodle_link_on_login: Not a Telegram login action, skipping.' );
                return;
            }

            kab_log( 'check_moodle_link_on_login: Proceeding to ensure_moodle_link.' );
            self::ensure_moodle_link( $user_login, $user );
        } catch ( \Throwable $e ) {
            kab_log( 'check_moodle_link_on_login ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        }
    }

    /**
     * Verify the user has a moodle_user_id meta entry. If not, attempt
     * to create the link via Edwiser Bridge.
     *
     * Called from two contexts:
     * - wp_login action (2 args): $user_login_or_user = string, $user = WP_User
     * - wptelegram_login_after_user_login (1 arg): $user_login_or_user = WP_User
     *
     * @param string|WP_User $user_login_or_user Username string or WP_User object.
     * @param WP_User|null   $user               User object (when called with 2 args).
     */
    public static function ensure_moodle_link( $user_login_or_user, $user = null ) {
        try {
            // Handle single-argument call: action passes only WP_User.
            if ( $user_login_or_user instanceof WP_User && null === $user ) {
                $user = $user_login_or_user;
            }

            kab_log( 'ensure_moodle_link called for user ID: ' . ( $user instanceof WP_User ? $user->ID : 'unknown' ) );

            if ( ! $user instanceof WP_User ) {
                kab_log( 'ensure_moodle_link: No valid WP_User, skipping.' );
                return;
            }

            $moodle_user_id = get_user_meta( $user->ID, 'moodle_user_id', true );
            if ( ! empty( $moodle_user_id ) ) {
                kab_log( 'ensure_moodle_link: Already linked to Moodle user ' . $moodle_user_id );
                return;
            }

            kab_log( 'ensure_moodle_link: No Moodle link, attempting to link.' );
            self::link_user_to_moodle( $user );
        } catch ( \Throwable $e ) {
            kab_log( 'ensure_moodle_link ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        }
    }

    /**
     * Attempt to create a Moodle account link via Edwiser Bridge.
     *
     * @param WP_User $user WordPress user.
     */
    private static function link_user_to_moodle( $user ) {
        if ( ! function_exists( 'edwiser_bridge_instance' ) ) {
            kab_log( 'link_user_to_moodle: edwiser_bridge_instance() not available.' );
            return;
        }

        try {
            kab_log( 'link_user_to_moodle: Calling edwiser_bridge_instance()...' );
            $eb = edwiser_bridge_instance();
            if ( $eb && method_exists( $eb, 'user_manager' ) ) {
                $manager = $eb->user_manager();
                if ( $manager && method_exists( $manager, 'link_moodle_user' ) ) {
                    $manager->link_moodle_user( $user );
                    kab_log( 'link_user_to_moodle: Successfully linked WP user ' . $user->ID );
                } else {
                    kab_log( 'link_user_to_moodle: link_moodle_user method not found.' );
                }
            } else {
                kab_log( 'link_user_to_moodle: user_manager method not found.' );
            }
        } catch ( \Throwable $e ) {
            kab_log( 'link_user_to_moodle ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        }
    }
}
