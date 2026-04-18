<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('jetwp_site_id');
delete_option('jetwp_hmac_secret');
delete_option('jetwp_cp_url');
delete_option('jetwp_heartbeat_interval');
delete_option('jetwp_last_heartbeat');
delete_option('jetwp_last_registered');
delete_option('jetwp_last_error');
delete_option('jetwp_pending_jobs');
delete_option('jetwp_send_error_log');
delete_option('jetwp_last_job_result');
delete_transient('jetwp_job_poll_lock');
delete_transient('jetwp_telemetry_cache');
