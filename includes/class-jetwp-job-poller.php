<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class JetWP_Job_Poller
{
    private const HOOK = 'jetwp_poll_jobs';
    private const LOCK_KEY = 'jetwp_job_poll_lock';

    public static function init(): void
    {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action(self::HOOK, [self::class, 'poll']);
        add_action('jetwp_heartbeat_sent', [self::class, 'poll_if_pending']);
        self::schedule();
    }

    public static function activate(): void
    {
        self::init();
        self::schedule();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        delete_transient(self::LOCK_KEY);
    }

    public static function cron_schedules(array $schedules): array
    {
        $schedules['jetwp_job_poll_interval'] = [
            'interval' => 60,
            'display' => 'JetWP job polling every 60 seconds',
        ];

        return $schedules;
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'jetwp_job_poll_interval', self::HOOK);
        }
    }

    public static function poll_if_pending(): void
    {
        if ((int) get_option('jetwp_pending_jobs', 0) > 0) {
            self::poll();
        }
    }

    public static function poll(): void
    {
        if (!JetWP_Registration::configured()) {
            return;
        }

        if (get_transient(self::LOCK_KEY)) {
            return;
        }

        set_transient(self::LOCK_KEY, time(), 55);

        try {
            $job = self::claim();
            if (!is_array($job)) {
                return;
            }

            $result = JetWP_Job_Runner::run($job);
            self::reportResult((string) $job['job_id'], $result);
        } catch (Throwable $exception) {
            update_option('jetwp_last_error', $exception->getMessage(), false);
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    private static function claim(): ?array
    {
        $cpUrl = rtrim((string) get_option('jetwp_cp_url', ''), '/');
        $siteId = (string) get_option('jetwp_site_id', '');
        $body = '';
        $response = wp_remote_post($cpUrl . '/api/v1/sites/' . rawurlencode($siteId) . '/jobs/claim', [
            'headers' => JetWP_Auth::signed_headers($body),
            'body' => $body,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data) || ($data['status'] ?? '') !== 'ok') {
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'Job claim failed.';
            throw new RuntimeException($message);
        }

        if (!is_array($data['data'] ?? null)) {
            update_option('jetwp_pending_jobs', 0, false);
            return null;
        }

        $job = $data['data'];
        update_option('jetwp_pending_jobs', max(0, (int) get_option('jetwp_pending_jobs', 0) - 1), false);

        return $job;
    }

    private static function reportResult(string $jobId, array $result): void
    {
        $cpUrl = rtrim((string) get_option('jetwp_cp_url', ''), '/');
        $siteId = (string) get_option('jetwp_site_id', '');
        $payload = [
            'job_id' => $jobId,
            'status' => (string) ($result['status'] ?? 'failed'),
            'output' => $result['output'] ?? null,
            'error_output' => $result['error_output'] ?? null,
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
        ];
        $body = wp_json_encode($payload);
        if (!is_string($body)) {
            throw new RuntimeException('Failed to encode agent job result.');
        }

        $response = wp_remote_post($cpUrl . '/api/v1/sites/' . rawurlencode($siteId) . '/job-result', [
            'headers' => JetWP_Auth::signed_headers($body),
            'body' => $body,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data) || ($data['status'] ?? '') !== 'ok') {
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'Job result reporting failed.';
            throw new RuntimeException($message);
        }

        update_option('jetwp_last_job_result', $payload, false);
    }
}
