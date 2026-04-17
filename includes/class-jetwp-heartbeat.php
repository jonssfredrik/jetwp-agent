<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class JetWP_Heartbeat
{
    private const HOOK = 'jetwp_heartbeat';

    public static function init(): void
    {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action(self::HOOK, [self::class, 'send']);
    }

    public static function activate(): void
    {
        self::init();
        self::schedule();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function cron_schedules(array $schedules): array
    {
        $interval = max(300, (int) get_option('jetwp_heartbeat_interval', 900));
        $schedules['jetwp_heartbeat_interval'] = [
            'interval' => $interval,
            'display' => sprintf('JetWP heartbeat every %d seconds', $interval),
        ];

        return $schedules;
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'jetwp_heartbeat_interval', self::HOOK);
        }
    }

    public static function send(bool $force_refresh = false): bool|WP_Error
    {
        if (!JetWP_Registration::configured()) {
            return new WP_Error('jetwp_not_configured', 'JetWP Agent is not registered.');
        }

        try {
            $payload = JetWP_Telemetry::cached($force_refresh);
            $body = wp_json_encode($payload);
            if (!is_string($body)) {
                return new WP_Error('jetwp_heartbeat_encode_failed', 'Heartbeat payload could not be encoded.');
            }

            $cp_url = rtrim((string) get_option('jetwp_cp_url', ''), '/');
            $site_id = (string) get_option('jetwp_site_id', '');
            $response = wp_remote_post($cp_url . '/api/v1/sites/' . rawurlencode($site_id) . '/heartbeat', [
                'headers' => JetWP_Auth::signed_headers($body),
                'body' => $body,
                'timeout' => 20,
            ]);
        } catch (Throwable $exception) {
            update_option('jetwp_last_error', $exception->getMessage(), false);
            return new WP_Error('jetwp_heartbeat_failed', $exception->getMessage());
        }

        if (is_wp_error($response)) {
            update_option('jetwp_last_error', $response->get_error_message(), false);
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300 || !is_array($data) || ($data['status'] ?? '') !== 'ok') {
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'Heartbeat failed.';
            update_option('jetwp_last_error', $message, false);
            return new WP_Error('jetwp_heartbeat_rejected', $message, ['status' => $status]);
        }

        update_option('jetwp_last_heartbeat', gmdate('c'), false);
        update_option('jetwp_pending_jobs', (int) ($data['pending_jobs'] ?? 0), false);
        delete_option('jetwp_last_error');

        do_action('jetwp_heartbeat_sent', $response);

        return true;
    }
}
