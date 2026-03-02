<?php
/**
 * Admin settings for auth_telegram_wp.
 *
 * @package    auth_telegram_wp
 * @copyright  2026 KAB Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'MOODLE_INTERNAL' ) || die();

if ( $ADMIN->fulltree ) {

    $settings->add(
        new admin_setting_configtext(
            'auth_telegram_wp/wp_login_url',
            get_string( 'wp_login_url', 'auth_telegram_wp' ),
            get_string( 'wp_login_url_desc', 'auth_telegram_wp' ),
            'https://kabacademy.com/wp-login.php',
            PARAM_URL
        )
    );

    $settings->add(
        new admin_setting_configtext(
            'auth_telegram_wp/button_text',
            get_string( 'button_text', 'auth_telegram_wp' ),
            get_string( 'button_text_desc', 'auth_telegram_wp' ),
            get_string( 'login_button', 'auth_telegram_wp' ),
            PARAM_TEXT
        )
    );
}
