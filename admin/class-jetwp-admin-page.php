<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class JetWP_Admin_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_jetwp_save_settings', [self::class, 'save_settings']);
        add_action('admin_post_jetwp_register', [self::class, 'register']);
        add_action('admin_post_jetwp_send_heartbeat', [self::class, 'send_heartbeat']);
        add_action('admin_post_jetwp_test_connection', [self::class, 'test_connection']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu(): void
    {
        add_options_page(
            'JetWP Agent',
            'JetWP Agent',
            'manage_options',
            'jetwp-agent',
            [self::class, 'render']
        );
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'settings_page_jetwp-agent') {
            return;
        }

        wp_enqueue_style('jetwp-agent-admin', JETWP_AGENT_URL . 'assets/admin.css', [], JETWP_AGENT_VERSION);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        $message = isset($_GET['jetwp_message']) ? sanitize_text_field(wp_unslash($_GET['jetwp_message'])) : '';
        $error = isset($_GET['jetwp_error']) ? sanitize_text_field(wp_unslash($_GET['jetwp_error'])) : '';

        require JETWP_AGENT_DIR . 'admin/views/settings.php';
    }

    public static function save_settings(): void
    {
        self::guard('jetwp_save_settings');

        $cp_url = isset($_POST['jetwp_cp_url']) ? esc_url_raw(wp_unslash($_POST['jetwp_cp_url'])) : '';
        $interval = isset($_POST['jetwp_heartbeat_interval']) ? (int) $_POST['jetwp_heartbeat_interval'] : 900;
        $send_error_log = isset($_POST['jetwp_send_error_log']);

        update_option('jetwp_cp_url', rtrim($cp_url, '/'), false);
        update_option('jetwp_heartbeat_interval', max(300, $interval), false);
        update_option('jetwp_send_error_log', $send_error_log, false);
        wp_clear_scheduled_hook('jetwp_heartbeat');
        JetWP_Heartbeat::schedule();

        self::redirect(['jetwp_message' => 'Settings saved.']);
    }

    public static function register(): void
    {
        self::guard('jetwp_register');

        $cp_url = isset($_POST['jetwp_cp_url']) ? esc_url_raw(wp_unslash($_POST['jetwp_cp_url'])) : (string) get_option('jetwp_cp_url', '');
        $pairing_token = isset($_POST['jetwp_pairing_token']) ? sanitize_text_field(wp_unslash($_POST['jetwp_pairing_token'])) : '';
        $result = JetWP_Registration::register_site($cp_url, $pairing_token);

        if (is_wp_error($result)) {
            self::redirect(['jetwp_error' => $result->get_error_message()]);
        }

        JetWP_Heartbeat::schedule();
        self::redirect(['jetwp_message' => 'Site registered with JetWP Control Plane.']);
    }

    public static function send_heartbeat(): void
    {
        self::guard('jetwp_send_heartbeat');

        $result = JetWP_Heartbeat::send(true);
        if (is_wp_error($result)) {
            self::redirect(['jetwp_error' => $result->get_error_message()]);
        }

        self::redirect(['jetwp_message' => 'Heartbeat sent.']);
    }

    public static function test_connection(): void
    {
        self::guard('jetwp_test_connection');

        $cp_url = isset($_POST['jetwp_cp_url']) ? esc_url_raw(wp_unslash($_POST['jetwp_cp_url'])) : '';
        $cp_url = rtrim($cp_url, '/');

        if ($cp_url === '' || filter_var($cp_url, FILTER_VALIDATE_URL) === false) {
            self::redirect(['jetwp_error' => 'Enter a valid Control Plane URL before testing.']);
        }

        $response = wp_remote_get($cp_url . '/api/v1/health', [
            'timeout' => 10,
            'redirection' => 2,
        ]);

        if (is_wp_error($response)) {
            self::redirect(['jetwp_error' => 'Connection failed: ' . $response->get_error_message()]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300 || !is_array($data) || ($data['status'] ?? '') !== 'ok') {
            self::redirect(['jetwp_error' => sprintf(
                'Control Plane responded with HTTP %d but did not return a valid health payload.',
                $status
            )]);
        }

        self::redirect(['jetwp_message' => sprintf(
            'Control Plane reachable at %s (service: %s).',
            $cp_url,
            (string) ($data['service'] ?? 'unknown')
        )]);
    }

    private static function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        check_admin_referer($action);
    }

    private static function redirect(array $args): void
    {
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php?page=jetwp-agent')));
        exit;
    }
}
