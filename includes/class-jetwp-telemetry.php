<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class JetWP_Telemetry
{
    public static function cached(bool $force_refresh = false): array
    {
        if (!$force_refresh) {
            $cached = JetWP_Cache::get_telemetry();
            if ($cached !== null) {
                return $cached;
            }
        }

        $payload = self::collect();
        JetWP_Cache::set_telemetry($payload, (int) get_option('jetwp_heartbeat_interval', 900));

        return $payload;
    }

    public static function collect(): array
    {
        global $wpdb;

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-includes/theme.php';
        }

        wp_update_plugins();
        wp_update_themes();
        wp_version_check();

        $coreUpdate = self::core_update();

        $payload = [
            'site_id' => (string) get_option('jetwp_site_id', ''),
            'timestamp' => gmdate('c'),
            'url' => home_url('/'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => method_exists($wpdb, 'db_version') ? $wpdb->db_version() : null,
            'is_multisite' => is_multisite(),
            'active_theme' => self::active_theme(),
            'plugins' => self::plugins(),
            'themes' => self::themes(),
            'core' => [
                'current_version' => get_bloginfo('version'),
                'available_update' => $coreUpdate,
            ],
            'core_update' => $coreUpdate,
            'disk_usage_mb' => self::disk_usage_mb(),
            'db_size_mb' => self::db_size_mb(),
            'cron_jobs' => self::cron_jobs(),
            'error_log_tail' => self::error_log_tail(),
            'site_health' => [
                'blog_public' => (int) get_option('blog_public', 1),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            ],
            'ssl_expiry' => null,
            'uptime_status' => 'ok',
        ];

        return apply_filters('jetwp_telemetry_data', $payload);
    }

    private static function active_theme(): array
    {
        $theme = wp_get_theme();

        return [
            'name' => $theme->get('Name'),
            'stylesheet' => $theme->get_stylesheet(),
            'version' => $theme->get('Version'),
        ];
    }

    private static function plugins(): array
    {
        $plugins = get_plugins();
        $updates = get_plugin_updates();
        $active = array_flip((array) get_option('active_plugins', []));
        $items = [];

        foreach ($plugins as $file => $data) {
            $items[] = [
                'slug' => dirname((string) $file) === '.' ? basename((string) $file, '.php') : dirname((string) $file),
                'file' => $file,
                'name' => $data['Name'] ?? '',
                'version' => $data['Version'] ?? '',
                'active' => isset($active[$file]),
                'update_available' => isset($updates[$file]) ? ($updates[$file]->update->new_version ?? null) : null,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            if ((bool) ($left['active'] ?? false) !== (bool) ($right['active'] ?? false)) {
                return (bool) ($left['active'] ?? false) ? -1 : 1;
            }

            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $items;
    }

    private static function themes(): array
    {
        $updates = function_exists('get_theme_updates') ? get_theme_updates() : [];
        $items = [];

        foreach (wp_get_themes() as $stylesheet => $theme) {
            $items[] = [
                'slug' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $stylesheet === get_stylesheet(),
                'update_available' => isset($updates[$stylesheet]) ? ($updates[$stylesheet]->update['new_version'] ?? null) : null,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            if ((bool) ($left['active'] ?? false) !== (bool) ($right['active'] ?? false)) {
                return (bool) ($left['active'] ?? false) ? -1 : 1;
            }

            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $items;
    }

    private static function core_update(): ?string
    {
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $updates = get_core_updates();
        if (!is_array($updates) || $updates === []) {
            return null;
        }

        $first = $updates[0];
        if (isset($first->response) && $first->response === 'upgrade') {
            return isset($first->version) ? (string) $first->version : null;
        }

        return null;
    }

    private static function disk_usage_mb(): ?int
    {
        if (!function_exists('get_dirsize')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!function_exists('get_dirsize')) {
            return null;
        }

        $bytes = (int) get_dirsize(ABSPATH);

        return $bytes > 0 ? (int) round($bytes / 1048576) : null;
    }

    private static function db_size_mb(): ?int
    {
        global $wpdb;

        $database = DB_NAME;
        $sql = $wpdb->prepare(
            'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
            $database
        );
        $bytes = $wpdb->get_var($sql);

        return is_numeric($bytes) ? (int) round(((float) $bytes) / 1048576) : null;
    }

    private static function cron_jobs(): array
    {
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return [];
        }

        $items = [];
        foreach ($cron as $timestamp => $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                $items[] = [
                    'hook' => (string) $hook,
                    'next_run' => gmdate('c', (int) $timestamp),
                    'event_count' => is_array($events) ? count($events) : 0,
                ];
                if (count($items) >= 50) {
                    return $items;
                }
            }
        }

        return $items;
    }

    private static function error_log_tail(): ?string
    {
        if (!get_option('jetwp_send_error_log', false)) {
            return null;
        }

        $log = WP_CONTENT_DIR . '/debug.log';
        if (!is_readable($log)) {
            return null;
        }

        $lines = file($log, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return null;
        }

        return implode("\n", array_slice($lines, -100));
    }
}
