<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class JetWP_Registration
{
    public static function register_site(string $control_plane_url, string $pairing_token): bool|WP_Error
    {
        $control_plane_url = rtrim(trim($control_plane_url), '/');
        $pairing_token = trim($pairing_token);

        if ($control_plane_url === '' || filter_var($control_plane_url, FILTER_VALIDATE_URL) === false) {
            return new WP_Error('jetwp_invalid_cp_url', 'Control Plane URL must be a valid URL.');
        }

        if ($pairing_token === '') {
            return new WP_Error('jetwp_missing_pairing_token', 'Pairing token is required.');
        }

        if (!JetWP_Auth::encryption_ready()) {
            return new WP_Error('jetwp_missing_encryption_key', 'JETWP_ENCRYPTION_KEY must be defined in wp-config.php before registration.');
        }

        $body = wp_json_encode([
            'pairing_token' => $pairing_token,
            'url' => home_url('/'),
            'label' => get_bloginfo('name') ?: wp_parse_url(home_url('/'), PHP_URL_HOST),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'wp_path' => ABSPATH,
        ]);

        if (!is_string($body)) {
            return new WP_Error('jetwp_registration_encode_failed', 'Registration payload could not be encoded.');
        }

        $response = wp_remote_post($control_plane_url . '/api/v1/sites/register', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            update_option('jetwp_last_error', $response->get_error_message(), false);
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300 || !is_array($data) || ($data['status'] ?? '') !== 'ok') {
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'Registration failed.';
            update_option('jetwp_last_error', $message, false);
            return new WP_Error('jetwp_registration_failed', $message, ['status' => $status]);
        }

        $site_id = (string) ($data['data']['site_id'] ?? '');
        $hmac_secret = (string) ($data['data']['hmac_secret'] ?? '');

        if ($site_id === '' || $hmac_secret === '') {
            return new WP_Error('jetwp_registration_invalid_response', 'Registration response did not include site_id and hmac_secret.');
        }

        update_option('jetwp_cp_url', $control_plane_url, false);
        update_option('jetwp_site_id', $site_id, false);
        update_option('jetwp_hmac_secret', JetWP_Auth::encrypt($hmac_secret), false);
        update_option('jetwp_last_registered', gmdate('c'), false);
        delete_option('jetwp_last_error');

        do_action('jetwp_registered', $site_id);

        return true;
    }

    public static function configured(): bool
    {
        return (string) get_option('jetwp_cp_url', '') !== ''
            && (string) get_option('jetwp_site_id', '') !== ''
            && (string) get_option('jetwp_hmac_secret', '') !== '';
    }
}
