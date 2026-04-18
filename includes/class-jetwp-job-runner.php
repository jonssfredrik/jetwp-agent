<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class JetWP_Job_Runner
{
    public static function supports(string $type): bool
    {
        return in_array($type, ['cache.flush', 'plugin.update'], true);
    }

    public static function run(array $job): array
    {
        $startedAt = microtime(true);

        try {
            $type = trim((string) ($job['type'] ?? ''));
            $params = is_array($job['params'] ?? null) ? $job['params'] : [];

            if (!self::supports($type)) {
                throw new RuntimeException('Unsupported agent job type: ' . $type);
            }

            $output = match ($type) {
                'cache.flush' => self::flushCache(),
                'plugin.update' => self::updatePlugin($params),
            };

            return [
                'status' => 'completed',
                'output' => wp_json_encode($output, JSON_UNESCAPED_SLASHES),
                'error_output' => null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'output' => null,
                'error_output' => $exception->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }
    }

    private static function flushCache(): array
    {
        global $wpdb;

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        $deleted = 0;
        $optionsTable = $wpdb->options;
        $deleted += (int) $wpdb->query(
            "DELETE FROM {$optionsTable}
             WHERE LEFT(option_name, 11) = '_transient_'
                OR LEFT(option_name, 16) = '_site_transient_'"
        );

        if (is_multisite() && isset($wpdb->sitemeta)) {
            $siteMetaTable = $wpdb->sitemeta;
            $deleted += (int) $wpdb->query(
                "DELETE FROM {$siteMetaTable}
                 WHERE LEFT(meta_key, 16) = '_site_transient_'"
            );
        }

        JetWP_Cache::clear_telemetry();

        return [
            'message' => 'Cache flushed locally by JetWP Agent.',
            'deleted_transient_rows' => $deleted,
        ];
    }

    private static function updatePlugin(array $params): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $requestedSlug = trim((string) ($params['slug'] ?? ''));
        if ($requestedSlug === '') {
            throw new RuntimeException('plugin.update requires params.slug.');
        }

        wp_update_plugins();

        $pluginFile = self::findPluginFile($requestedSlug);
        if ($pluginFile === null) {
            throw new RuntimeException('Requested plugin slug was not found locally.');
        }

        $updates = get_site_transient('update_plugins');
        $response = is_object($updates) && isset($updates->response) && is_array($updates->response)
            ? $updates->response
            : [];

        $plugins = get_plugins();
        $beforeVersion = isset($plugins[$pluginFile]['Version']) ? (string) $plugins[$pluginFile]['Version'] : null;

        if (!array_key_exists($pluginFile, $response)) {
            return [
                'message' => 'No plugin update was available.',
                'slug' => $requestedSlug,
                'plugin' => $pluginFile,
                'version' => $beforeVersion,
            ];
        }

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->upgrade($pluginFile);
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }

        wp_clean_plugins_cache(true);
        $afterPlugins = get_plugins();

        return [
            'message' => 'Plugin update attempted locally by JetWP Agent.',
            'slug' => $requestedSlug,
            'plugin' => $pluginFile,
            'previous_version' => $beforeVersion,
            'updated_to' => isset($afterPlugins[$pluginFile]['Version']) ? (string) $afterPlugins[$pluginFile]['Version'] : null,
            'result' => $result,
        ];
    }

    private static function findPluginFile(string $requestedSlug): ?string
    {
        foreach (get_plugins() as $file => $data) {
            $slug = dirname((string) $file) === '.' ? basename((string) $file, '.php') : dirname((string) $file);
            if ($slug === $requestedSlug) {
                return (string) $file;
            }
        }

        return null;
    }
}
