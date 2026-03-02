<?php
/**
 * Telegram via WordPress authentication plugin.
 *
 * Shows a "Login with Telegram" button on the Moodle login page.
 * Clicking it redirects to the WordPress site where the actual Telegram
 * authentication happens. After successful login, Edwiser Bridge SSO
 * creates a Moodle session and redirects the user back.
 *
 * @package    auth_telegram_wp
 * @copyright  2026 KAB Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'MOODLE_INTERNAL' ) || die();

require_once( $CFG->libdir . '/authlib.php' );

/**
 * Telegram via WordPress authentication plugin.
 */
class auth_plugin_telegram_wp extends auth_plugin_base {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->authtype = 'telegram_wp';
        $this->config   = get_config( 'auth_telegram_wp' );
    }

    /**
     * This plugin does not handle authentication directly.
     * Authentication is delegated to WordPress + WP Telegram Login.
     *
     * @param string $username Username.
     * @param string $password Password.
     * @return bool Always false — auth happens on the WordPress side.
     */
    public function user_login( $username, $password ) {
        return false;
    }

    /**
     * Return identity provider list for the Moodle login page.
     *
     * This adds a "Login with Telegram" button that redirects to WordPress.
     *
     * @param string $wantsurl The URL the user was trying to access.
     * @return array Array of identity provider entries.
     */
    public function loginpage_idp_list( $wantsurl ) {
        $wp_url = $this->get_wp_login_url();
        if ( empty( $wp_url ) ) {
            return array();
        }

        // Build the redirect URL: WordPress login with Telegram, then back to Moodle.
        $params = array(
            'telegram_login' => '1',
        );

        if ( ! empty( $wantsurl ) ) {
            $params['moodle_redirect_to'] = $wantsurl;
        }

        $login_url = new moodle_url( $wp_url, $params );

        $button_text = ! empty( $this->config->button_text )
            ? $this->config->button_text
            : get_string( 'login_button', 'auth_telegram_wp' );

        return array(
            array(
                'url'  => $login_url,
                'name' => $button_text,
                'icon' => new pix_icon( 'telegram', $button_text, 'auth_telegram_wp' ),
            ),
        );
    }

    /**
     * Get the WordPress login URL from plugin settings.
     *
     * @return string WordPress login URL or empty string.
     */
    private function get_wp_login_url() {
        if ( ! empty( $this->config->wp_login_url ) ) {
            return $this->config->wp_login_url;
        }

        // Default fallback.
        return 'https://kabacademy.com/wp-login.php';
    }

    /**
     * No sign-up through this plugin.
     *
     * @return bool
     */
    public function can_signup() {
        return false;
    }

    /**
     * No password changes through this plugin.
     *
     * @return bool
     */
    public function can_change_password() {
        return false;
    }

    /**
     * No internal passwords.
     *
     * @return bool
     */
    public function is_internal() {
        return false;
    }

    /**
     * No password resets through this plugin.
     *
     * @return bool
     */
    public function can_reset_password() {
        return false;
    }

    /**
     * Plugin can be manually set for users.
     *
     * @return bool
     */
    public function can_be_manually_set() {
        return true;
    }
}
