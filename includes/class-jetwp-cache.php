<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class JetWP_Cache
{
    private const TELEMETRY_TRANSIENT = 'jetwp_telemetry_cache';

    public static function get_telemetry(): ?array
    {
        $cached = get_transient(self::TELEMETRY_TRANSIENT);

        return is_array($cached) ? $cached : null;
    }

    public static function set_telemetry(array $payload, int $ttl = 900): void
    {
        set_transient(self::TELEMETRY_TRANSIENT, $payload, max(60, $ttl));
    }

    public static function clear_telemetry(): void
    {
        delete_transient(self::TELEMETRY_TRANSIENT);
    }
}
