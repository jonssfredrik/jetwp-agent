<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class JetWP_Auth
{
    public static function encryption_ready(): bool
    {
        return defined('JETWP_ENCRYPTION_KEY') && is_string(JETWP_ENCRYPTION_KEY) && JETWP_ENCRYPTION_KEY !== '';
    }

    public static function encrypt(string $value): string
    {
        if (!self::encryption_ready()) {
            throw new RuntimeException('JETWP_ENCRYPTION_KEY is missing in wp-config.php.');
        }

        $key = hash('sha256', (string) JETWP_ENCRYPTION_KEY, true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, 0, $iv);

        if ($encrypted === false) {
            throw new RuntimeException('JetWP secret encryption failed.');
        }

        return base64_encode($iv) . '::' . $encrypted;
    }

    public static function decrypt(string $stored): string
    {
        if (!self::encryption_ready()) {
            throw new RuntimeException('JETWP_ENCRYPTION_KEY is missing in wp-config.php.');
        }

        [$iv_encoded, $encrypted] = array_pad(explode('::', $stored, 2), 2, null);
        if (!is_string($iv_encoded) || !is_string($encrypted)) {
            throw new RuntimeException('JetWP encrypted secret format is invalid.');
        }

        $iv = base64_decode($iv_encoded, true);
        if ($iv === false) {
            throw new RuntimeException('JetWP encrypted secret IV is invalid.');
        }

        $key = hash('sha256', (string) JETWP_ENCRYPTION_KEY, true);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);

        if ($decrypted === false) {
            throw new RuntimeException('JetWP secret decryption failed.');
        }

        return $decrypted;
    }

    public static function secret(): ?string
    {
        $stored = get_option('jetwp_hmac_secret');
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        return self::decrypt($stored);
    }

    public static function signed_headers(string $body): array
    {
        $site_id = (string) get_option('jetwp_site_id', '');
        $secret = self::secret();

        if ($site_id === '' || $secret === null) {
            throw new RuntimeException('JetWP site ID or HMAC secret is missing.');
        }

        $timestamp = (string) time();

        return [
            'Content-Type' => 'application/json',
            'X-JetWP-Site-Id' => $site_id,
            'X-JetWP-Timestamp' => $timestamp,
            'X-JetWP-Signature' => hash_hmac('sha256', $body . '|' . $timestamp, $secret),
        ];
    }

    public static function verify_rest_request(WP_REST_Request $request): bool|WP_Error
    {
        $site_id = (string) get_option('jetwp_site_id', '');
        $secret = self::secret();

        if ($site_id === '' || $secret === null) {
            return new WP_Error('jetwp_not_configured', 'JetWP Agent is not configured.', ['status' => 403]);
        }

        $header_site_id = (string) $request->get_header('x-jetwp-site-id');
        $timestamp = (string) $request->get_header('x-jetwp-timestamp');
        $signature = (string) $request->get_header('x-jetwp-signature');

        if ($header_site_id === '' || $timestamp === '' || $signature === '') {
            return new WP_Error('jetwp_missing_auth_headers', 'JetWP authentication headers are required.', ['status' => 401]);
        }

        if ($header_site_id !== $site_id || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 60) {
            return new WP_Error('jetwp_invalid_auth_headers', 'JetWP authentication headers are invalid.', ['status' => 403]);
        }

        $expected = hash_hmac('sha256', $request->get_body() . '|' . $timestamp, $secret);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('jetwp_invalid_signature', 'JetWP HMAC signature is invalid.', ['status' => 403]);
        }

        return true;
    }
}
