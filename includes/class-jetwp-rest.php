<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class JetWP_Rest
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route('jetwp/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'health'],
            'permission_callback' => [JetWP_Auth::class, 'verify_rest_request'],
        ]);

        register_rest_route('jetwp/v1', '/trigger', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'trigger'],
            'permission_callback' => [JetWP_Auth::class, 'verify_rest_request'],
        ]);

        register_rest_route('jetwp/v1', '/job-result', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'job_result'],
            'permission_callback' => [JetWP_Auth::class, 'verify_rest_request'],
        ]);
    }

    public static function health(): WP_REST_Response
    {
        return new WP_REST_Response([
            'status' => 'ok',
            'data' => JetWP_Telemetry::cached(),
        ]);
    }

    public static function trigger(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = $request->get_json_params();
        $action = is_array($payload) ? (string) ($payload['action'] ?? '') : '';
        $allowed = apply_filters('jetwp_allowed_trigger_actions', [
            'scan_plugins',
            'flush_cache',
            'collect_logs',
            'check_integrity',
            'refresh_telemetry',
        ]);

        if (!in_array($action, (array) $allowed, true)) {
            return new WP_Error('jetwp_action_not_allowed', 'JetWP action is not allowed.', ['status' => 400]);
        }

        if ($action === 'scan_plugins') {
            JetWP_Cache::clear_telemetry();
            return self::response(['plugins' => JetWP_Telemetry::cached(true)['plugins'] ?? []]);
        }

        if ($action === 'flush_cache') {
            wp_cache_flush();
            JetWP_Cache::clear_telemetry();
            return self::response(['flushed' => true]);
        }

        if ($action === 'collect_logs') {
            update_option('jetwp_send_error_log', true, false);
            $telemetry = JetWP_Telemetry::cached(true);
            update_option('jetwp_send_error_log', false, false);
            return self::response(['error_log_tail' => $telemetry['error_log_tail'] ?? null]);
        }

        if ($action === 'check_integrity') {
            return self::response([
                'message' => 'Integrity checks are executed by the Control Plane Runner via SSH + WP-CLI in MVP.',
            ]);
        }

        $sent = JetWP_Heartbeat::send(true);
        if (is_wp_error($sent)) {
            return $sent;
        }

        return self::response(['heartbeat_sent' => true]);
    }

    public static function job_result(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        update_option('jetwp_last_job_result', is_array($payload) ? $payload : [], false);

        return self::response(['stored' => true]);
    }

    private static function response(array $data): WP_REST_Response
    {
        return new WP_REST_Response([
            'status' => 'ok',
            'data' => $data,
        ]);
    }
}
