# JetWP Agent

Lightweight WordPress plugin that registers a site with the
[JetWP Control Plane](https://github.com/jonssfredrik/jetwp-control-plane),
sends signed heartbeat telemetry on a schedule, and exposes a small set of
safe REST actions the Control Plane can trigger.

The Agent never executes destructive operations on its own. Updates,
integrity checks, DB optimization, and similar mutations are performed by
the Control Plane's Runner over SSH + WP-CLI — the Agent only reports state
and accepts narrow, idempotent triggers.

## Features

- One-time pairing-token registration against the Control Plane
- HMAC-SHA256 signed heartbeat every 15 minutes (configurable)
- Telemetry: WP version, PHP version, active theme, plugin list, core
  update info, disk usage, DB size, uptime status
- Cached telemetry payload to keep heartbeat cost low
- Encrypted at-rest storage of the HMAC secret (uses
  `JETWP_ENCRYPTION_KEY`)
- Settings page with **Test Connection** and **Send Heartbeat Now**
  shortcuts
- REST endpoints (`jetwp/v1/...`) for safe Control-Plane-initiated actions:
  `health`, `trigger`, `job-result`

## Requirements

- WordPress 6.0+
- PHP 8.0+
- A reachable JetWP Control Plane URL

## Installation

1. Copy this folder to `wp-content/plugins/jetwp-agent/` on the target
   site (or upload as a zip)
2. Add an encryption key to `wp-config.php`:
   ```php
   define('JETWP_ENCRYPTION_KEY', 'a-strong-random-string-32-chars-or-longer');
   ```
3. Activate the plugin in **Plugins → Installed Plugins**
4. Go to **Settings → JetWP Agent**

## Pairing With the Control Plane

1. On the Control Plane, generate a pairing token:
   ```bash
   php cli.php token:create --server-id=<id>
   ```
2. In WP admin → **Settings → JetWP Agent**:
   - Enter the **Control Plane URL** (e.g. `https://cp.example.com`)
   - Paste the **Pairing token**
   - Click **Register with Control Plane**
3. On success, the plugin stores `jetwp_site_id` and an encrypted
   `jetwp_hmac_secret`, schedules the heartbeat cron, and confirms in the
   admin notice
4. Click **Send Heartbeat Now** to verify connectivity end-to-end

## Changing Settings After Registration

The settings card lets you safely update:

- **Control Plane URL** — site_id and HMAC secret are preserved, no
  re-registration required. Use **Test Connection** to verify reachability
  via `<cp_url>/api/v1/health` before saving.
- **Heartbeat interval** — minimum 300 seconds, default 900 (15 min)
- **Error log tail** — opt in to including the tail of
  `wp-content/debug.log` in telemetry

You can also change values via WP-CLI without touching the admin UI:

```bash
wp option update jetwp_cp_url https://new-cp.example.com
wp option update jetwp_heartbeat_interval 600
wp cron event run jetwp_heartbeat   # force an immediate heartbeat
```

## File Layout

```
jetwp-agent/
├── jetwp-agent.php                       # plugin bootstrap + hook wiring
├── uninstall.php                         # cleanup on plugin removal
├── includes/
│   ├── class-jetwp-auth.php              # HMAC signing + secret encryption
│   ├── class-jetwp-cache.php             # telemetry caching layer
│   ├── class-jetwp-heartbeat.php         # WP-Cron scheduler + send loop
│   ├── class-jetwp-registration.php     # one-time pairing exchange
│   ├── class-jetwp-rest.php              # /jetwp/v1/* REST routes
│   └── class-jetwp-telemetry.php         # payload collection
├── admin/
│   ├── class-jetwp-admin-page.php        # Settings → JetWP Agent screen
│   └── views/settings.php                # admin form markup
└── assets/
    └── admin.css                         # settings page styling
```

## REST Endpoints (exposed on this site)

All endpoints require Control-Plane HMAC headers; they are *not* open to
the public.

| Method | Route                  | Purpose                                 |
| ------ | ---------------------- | --------------------------------------- |
| GET    | `/wp-json/jetwp/v1/health`     | Returns latest cached telemetry  |
| POST   | `/wp-json/jetwp/v1/trigger`    | Run a safe local action          |
| POST   | `/wp-json/jetwp/v1/job-result` | Receive a job result for storage |

Allowed `trigger` actions (filterable via `jetwp_allowed_trigger_actions`):

- `scan_plugins`
- `flush_cache`
- `collect_logs`
- `check_integrity` *(MVP: returns a notice; Runner does this work)*
- `refresh_telemetry`

## Heartbeat Payload

Roughly:

```json
{
  "wp_version": "6.9.4",
  "php_version": "8.2.12",
  "active_theme": "twentytwentythree",
  "plugins": [{ "slug": "akismet", "version": "5.3", "active": true }],
  "core_updates": { "available": false },
  "disk": { "used_mb": 1234, "free_mb": 9012 },
  "db": { "size_mb": 47.3 },
  "uptime": { "ok": true }
}
```

Signed via HMAC-SHA256 over `body + "|" + unix_timestamp` and posted to
`<cp_url>/api/v1/sites/{site_id}/heartbeat` with these headers:

- `X-JetWP-Site-Id`
- `X-JetWP-Timestamp`
- `X-JetWP-Signature`

## Stored WordPress Options

| Option                       | Purpose                                       |
| ---------------------------- | --------------------------------------------- |
| `jetwp_cp_url`               | Control Plane base URL                        |
| `jetwp_site_id`              | UUID issued at registration                   |
| `jetwp_hmac_secret`          | HMAC secret, encrypted with `JETWP_ENCRYPTION_KEY` |
| `jetwp_heartbeat_interval`   | Cron interval in seconds (≥300)               |
| `jetwp_last_heartbeat`       | ISO 8601 timestamp of last successful send    |
| `jetwp_pending_jobs`         | Pending job count returned by last heartbeat  |
| `jetwp_last_error`           | Last error message (cleared on success)       |
| `jetwp_send_error_log`       | Whether to include debug.log tail in payload  |

`uninstall.php` removes all of the above when the plugin is deleted.

## Troubleshooting

- **Heartbeat never arrives at Control Plane.** Confirm `jetwp_cp_url`
  resolves and is reachable from this server (use **Test Connection**).
  The most common cause is a `localhost` URL that is unreachable from a
  remote host.
- **`JETWP_ENCRYPTION_KEY is missing` notice.** Add the constant to
  `wp-config.php` before registering. Without it, secrets cannot be
  stored.
- **Registration returns 403.** Pairing token is expired, already used,
  or not assigned to a server. Generate a fresh one on the Control Plane.

## License

Proprietary / private project. No license granted unless explicitly noted.
