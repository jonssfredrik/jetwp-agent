<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$cp_url = (string) get_option('jetwp_cp_url', 'http://localhost:8080');
$site_id = (string) get_option('jetwp_site_id', '');
$interval = (int) get_option('jetwp_heartbeat_interval', 900);
$last_heartbeat = (string) get_option('jetwp_last_heartbeat', '');
$last_error = (string) get_option('jetwp_last_error', '');
$pending_jobs = (int) get_option('jetwp_pending_jobs', 0);
$send_error_log = (bool) get_option('jetwp_send_error_log', false);
?>
<div class="wrap jetwp-agent">
    <h1>JetWP Agent</h1>

    <?php if ($message !== ''): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>

    <?php if ($error !== '' || $last_error !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error !== '' ? $error : $last_error); ?></p></div>
    <?php endif; ?>

    <?php if (!JetWP_Auth::encryption_ready()): ?>
        <div class="notice notice-error">
            <p><strong>JETWP_ENCRYPTION_KEY is missing.</strong> Define it in wp-config.php before registration.</p>
        </div>
    <?php endif; ?>

    <div class="jetwp-grid">
        <section class="jetwp-card">
            <h2>Status</h2>
            <table class="widefat striped">
                <tbody>
                <tr><th>Registered</th><td><?php echo $site_id !== '' ? 'Yes' : 'No'; ?></td></tr>
                <tr><th>Site ID</th><td><code><?php echo esc_html($site_id !== '' ? $site_id : 'n/a'); ?></code></td></tr>
                <tr><th>Last heartbeat</th><td><?php echo esc_html($last_heartbeat !== '' ? $last_heartbeat : 'Never'); ?></td></tr>
                <tr><th>Pending jobs</th><td><?php echo esc_html((string) $pending_jobs); ?></td></tr>
                <tr><th>Encryption key</th><td><?php echo JetWP_Auth::encryption_ready() ? 'Configured' : 'Missing'; ?></td></tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="jetwp-actions">
                <?php wp_nonce_field('jetwp_send_heartbeat'); ?>
                <input type="hidden" name="action" value="jetwp_send_heartbeat">
                <?php submit_button(
                    'Send Heartbeat Now',
                    'secondary',
                    'submit',
                    false,
                    ($site_id === '' || !JetWP_Auth::encryption_ready()) ? ['disabled' => 'disabled'] : []
                ); ?>
            </form>
        </section>

        <section class="jetwp-card">
            <h2>Settings</h2>
            <?php if ($site_id !== ''): ?>
                <p class="description">
                    Changing the Control Plane URL keeps your registration intact
                    (<code>site_id</code> and HMAC secret are preserved). Use
                    <strong>Test Connection</strong> to verify the new URL is reachable
                    from this site before saving.
                </p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('jetwp_save_settings'); ?>
                <input type="hidden" name="action" value="jetwp_save_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="jetwp_cp_url">Control Plane URL</label></th>
                        <td><input id="jetwp_cp_url" name="jetwp_cp_url" type="url" class="regular-text" value="<?php echo esc_attr($cp_url); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jetwp_heartbeat_interval">Heartbeat interval</label></th>
                        <td><input id="jetwp_heartbeat_interval" name="jetwp_heartbeat_interval" type="number" min="300" step="60" value="<?php echo esc_attr((string) $interval); ?>"> seconds</td>
                    </tr>
                    <tr>
                        <th scope="row">Error log tail</th>
                        <td><label><input name="jetwp_send_error_log" type="checkbox" value="1" <?php checked($send_error_log); ?>> Include wp-content/debug.log tail in telemetry</label></td>
                    </tr>
                </table>

                <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="jetwp-actions">
                <?php wp_nonce_field('jetwp_test_connection'); ?>
                <input type="hidden" name="action" value="jetwp_test_connection">
                <input type="hidden" name="jetwp_cp_url" value="<?php echo esc_attr($cp_url); ?>">
                <?php submit_button('Test Connection', 'secondary', 'submit', false); ?>
                <span class="description">Pings <code><?php echo esc_html($cp_url); ?>/api/v1/health</code>.</span>
            </form>
        </section>

        <section class="jetwp-card">
            <h2>Register Site</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('jetwp_register'); ?>
                <input type="hidden" name="action" value="jetwp_register">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="jetwp_register_cp_url">Control Plane URL</label></th>
                        <td><input id="jetwp_register_cp_url" name="jetwp_cp_url" type="url" class="regular-text" value="<?php echo esc_attr($cp_url); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="jetwp_pairing_token">Pairing token</label></th>
                        <td><input id="jetwp_pairing_token" name="jetwp_pairing_token" type="text" class="regular-text" autocomplete="off"></td>
                    </tr>
                </table>

                <?php submit_button(
                    $site_id !== '' ? 'Register Again' : 'Register with Control Plane',
                    'primary',
                    'submit',
                    true,
                    !JetWP_Auth::encryption_ready() ? ['disabled' => 'disabled'] : []
                ); ?>
            </form>
        </section>
    </div>
</div>
