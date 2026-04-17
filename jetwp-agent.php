<?php
/**
 * Plugin Name: JetWP Agent
 * Description: Lightweight Agent for JetWP Control Plane registration, telemetry, and safe local actions.
 * Version: 0.1.0
 * Author: JetWP
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('JETWP_AGENT_VERSION', '0.1.0');
define('JETWP_AGENT_FILE', __FILE__);
define('JETWP_AGENT_DIR', plugin_dir_path(__FILE__));
define('JETWP_AGENT_URL', plugin_dir_url(__FILE__));

require_once JETWP_AGENT_DIR . 'includes/class-jetwp-cache.php';
require_once JETWP_AGENT_DIR . 'includes/class-jetwp-auth.php';
require_once JETWP_AGENT_DIR . 'includes/class-jetwp-telemetry.php';
require_once JETWP_AGENT_DIR . 'includes/class-jetwp-registration.php';
require_once JETWP_AGENT_DIR . 'includes/class-jetwp-heartbeat.php';
require_once JETWP_AGENT_DIR . 'includes/class-jetwp-rest.php';
require_once JETWP_AGENT_DIR . 'admin/class-jetwp-admin-page.php';

register_activation_hook(__FILE__, ['JetWP_Heartbeat', 'activate']);
register_deactivation_hook(__FILE__, ['JetWP_Heartbeat', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    JetWP_Heartbeat::init();
    JetWP_Rest::init();

    if (is_admin()) {
        JetWP_Admin_Page::init();
    }
});
