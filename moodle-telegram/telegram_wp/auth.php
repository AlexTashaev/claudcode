<?php
/**
 * Legacy wrapper for auth_telegram_wp.
 *
 * Moodle's get_auth_plugin() loads this file and expects class auth_plugin_telegram_wp.
 * Delegate to the namespaced class in classes/auth.php.
 *
 * @package    auth_telegram_wp
 * @copyright  2026 KAB Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * Legacy class name expected by Moodle's get_auth_plugin().
 */
class auth_plugin_telegram_wp extends \auth_telegram_wp\auth {
}
