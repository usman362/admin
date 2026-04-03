# OSINT Supplier Risk Dashboard

A PHP-based web dashboard for monitoring supplier security, financial, and supply chain risks using free public OSINT sources.

---

## Features

- **Auto-ingests** 10 pre-configured RSS/news feeds (CISA, NVD/CVE, Bleeping Computer, Krebs, SecurityWeek, The Hacker News, Google News)
- **Keyword matching** with configurable risk levels (High / Medium / Low) and risk types (Security / Financial / Supply Chain / Geopolitical)
- **Supplier matching** — alerts automatically linked to tracked suppliers/manufacturers
- **Live dashboard** with summary cards, filterable alert table, detail modals
- **Admin panel** — fully manage suppliers, keywords, sources, and settings via browser
- **Email notifications** for High-risk alerts (optional, uses PHP mail)
- **Export** to JSON or CSV
- **Auto-refresh** every 5 minutes
- **Dismiss/archive** alerts
- **Cron execution log** — see what was fetched, when, and what alerts were created

---

## Requirements

- PHP 8.0+ with extensions: `pdo_mysql`, `simplexml`, `mbstring`
- MySQL 5.7+ or MariaDB 10.3+
- A web server (Apache/Nginx)
- Cron access (for automatic feed fetching)

---

## Installation

### 1. Database Setup

```bash
mysql -u root -p < database.sql
```

Or import `database.sql` via phpMyAdmin.

### 2. Configure the Application

Edit `config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'osint_dashboard');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// IMPORTANT: Change this admin password!
define('ADMIN_PASSWORD', 'your_secure_password_here');
```

### 3. Upload Files

Upload all files to your web server. Recommended structure:

```
/var/www/html/osint-dashboard/
├── config.php
├── index.php          ← Main dashboard
├── api.php            ← JSON API
├── database.sql       ← Run once to create DB
├── admin/
│   ├── index.php      ← Admin panel
│   └── admin.css
├── assets/
│   ├── css/dashboard.css
│   └── js/dashboard.js
├── cron/
│   └── fetch_feeds.php
└── data/              ← Logs (must be writable)
```

Make the `data/` directory writable:
```bash
chmod 755 data/
```

### 4. Set Up Cron Job

Add to crontab (`crontab -e`):

```cron
# Fetch OSINT feeds every 30 minutes
*/30 * * * * php /var/www/html/osint-dashboard/cron/fetch_feeds.php >> /var/www/html/osint-dashboard/data/cron.log 2>&1
```

### 5. Test the Fetcher

Run manually to verify everything works:

```bash
php /var/www/html/osint-dashboard/cron/fetch_feeds.php
```

You should see output like:
```
[2025-01-15 14:30:00] OSINT Fetcher started. Sources due: 10
  Source: CISA Alerts — 8 items
    ✓ [High] Critical Vulnerability in Apache HTTP Server...
  Source: Bleeping Computer — 25 items
    ✓ [High] Major retailer hit by ransomware attack...
[2025-01-15 14:30:45] Done. Alerts created: 6
```

---

## Usage

### Dashboard (`/index.php`)

- **Summary cards** — total alerts and counts by risk level
- **Risk type breakdown** — Security / Financial / Supply Chain / Geopolitical
- **Filter bar** — filter by risk level, type, source, supplier, time range, or keyword search
- **Alert table** — sortable by date, risk, supplier, source
- **Detail modal** — click any alert title to see full summary and link
- **Dismiss** — ✕ button removes alert from view (archived, not deleted)
- **Export** — download all active alerts as JSON or CSV
- **Auto-refreshes** every 5 minutes

### Admin Panel (`/admin/`)

Default password: `change_me_now` — **change this immediately** in `config.php`.

Sections:
- **Overview** — system stats and cron status
- **Suppliers** — add/remove/enable/disable suppliers and their aliases
- **Keywords** — manage alert keywords with risk type and level
- **Sources** — manage RSS feed URLs and fetch intervals
- **Alerts Log** — view all alerts including dismissed ones; export
- **Cron Log** — monitor feed fetch history and errors
- **Settings** — email notifications, retention policy, dashboard title

---

## Adding Suppliers

In the Admin Panel → Suppliers, add your contract manufacturers and suppliers:

| Field | Example |
|-------|---------|
| Name | ACME Electronics Co |
| Aliases | ACME, AEC, Acme Electronics |
| Category | PCB Manufacturer |
| Country | Taiwan |
| Criticality | Critical |

The fetcher will scan all feed items for the supplier name AND all aliases, case-insensitively.

---

## Customising Keywords

Default keywords include: `breach`, `ransomware`, `vulnerability`, `exploit`, `insolvency`, `bankruptcy`, `sanctions`, `export ban`, `supply disruption`, `shortage`, `recall`, `counterfeit`, and more.

Add industry-specific terms via Admin → Keywords:
- `counterfeit components` → Supply Chain / High
- `factory explosion` → Supply Chain / High  
- `SEC investigation` → Financial / High
- `component shortage` → Supply Chain / Medium

---

## Email Notifications

1. In Admin → Settings, enable "Email Notifications"
2. Enter your notification email address
3. Set minimum risk level (default: High)
4. For SMTP, edit `cron/fetch_feeds.php` — the `sendPendingNotifications()` method uses PHP's built-in `mail()` function. For reliable SMTP delivery, integrate PHPMailer or SwiftMailer.

---

## Security Recommendations

1. **Change the admin password** in `config.php` immediately
2. **Restrict `/admin/`** with `.htaccess` IP whitelist or HTTP Basic Auth
3. **Move `config.php`** above the web root if possible
4. **HTTPS only** — use SSL/TLS (Let's Encrypt)
5. **Writable directories** — only `data/` should be writable by the web server
6. **Database user** — give the DB user only SELECT/INSERT/UPDATE/DELETE on `osint_dashboard`, not GRANT or DROP

---

## API Endpoints

| Endpoint | Description |
|----------|-------------|
| `api.php?action=alerts` | List alerts (paginated, filterable) |
| `api.php?action=stats` | Summary statistics and trend data |
| `api.php?action=filter_options` | Available source/supplier filter values |
| `api.php?action=alert&id=X` | Single alert detail |
| `api.php?action=export&format=json` | Export all as JSON |
| `api.php?action=export&format=csv` | Export all as CSV |
| `POST api.php?action=dismiss&id=X` | Dismiss an alert |

---

## Architecture

```
[Public Feeds]          [Cron: every 30min]
CISA, NVD, RSS  ──────► fetch_feeds.php
                              │
                    ┌─────────▼──────────┐
                    │   MySQL Database   │
                    │  alerts / sources  │
                    │  keywords / suppl. │
                    └─────────┬──────────┘
                              │
              ┌───────────────┼─────────────────┐
              ▼               ▼                 ▼
         api.php          index.php         admin/
         (JSON API)       (Dashboard)       (Admin Panel)
```

---

## Extending

- **Add new feeds**: Admin → Sources → add any RSS/Atom URL
- **Custom alert types**: Extend the `risk_type` ENUM in database and PHP
- **Webhooks**: Add Slack/Teams webhook in `sendPendingNotifications()`  
- **HaveIBeenPwned**: Add your domain monitoring via their free API: `https://haveibeenpwned.com/api/v3/breachedaccount/`
- **SMTP with PHPMailer**: Replace `mail()` call with PHPMailer for reliable delivery

---

## Troubleshooting

**No alerts appearing:**
- Run the cron script manually and check output
- Check `data/error.log` for PHP errors
- Verify DB credentials in `config.php`
- Test DB connection: `php -r "require 'config.php'; getDB(); echo 'OK';"`

**Feeds not fetching:**
- Check server outbound internet access
- Some feeds require cURL — enable `php-curl` extension
- Check feed URL is a valid RSS/Atom feed

**Admin panel redirect loop:**
- Clear browser cookies/session data
- Verify `session_start()` is not blocked

---

## License

MIT — Free to use and modify for personal and commercial purposes.
