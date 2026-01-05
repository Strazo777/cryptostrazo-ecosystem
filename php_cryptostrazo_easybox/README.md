# CryptoStrazo Webhook Client (Pure PHP) — EasyBox

This package adds **one public URL** to your website for receiving CryptoStrazo webhooks:

- `https://SITE.COM/webhooks/cryptostrazo`

At the same time:

- the `cs_webhooks/` folder is **not directly accessible via URL**
- your main website **is not affected** (the `.htaccess` rule is very specific)
- the webhook is acknowledged with `200 OK` **only after it is successfully saved to the database**
- data is stored in the `strz_webhook_inbox` table (**readable columns** + full `payload_json`)

---

## 1) How it works (in simple terms)

1) CryptoStrazo sends a `POST` request to your site: `/webhooks/cryptostrazo`  
2) Apache/LiteSpeed, using a rule in the **root** `.htaccess`, rewrites **only this URL** to:
   - `cs_webhooks/public/index.php`
3) The receiver:
   - verifies the signature and timestamp
   - stores the verified payload in the database (SQLite or MySQL)
   - returns `200 OK` only after a successful write (otherwise `500`, so CryptoStrazo will retry)

---

## 2) Hosting requirements

- PHP **8.1+** (8.2/8.3 recommended)
- PDO extension:
  - `pdo_mysql` for **MySQL/MariaDB** (recommended)
  - `pdo_sqlite` for **SQLite** (quick start, no control panel needed)

---

## 3) Installation (exactly the way you want)

### Step A — Upload the folder
Upload the `cs_webhooks/` folder to your site DocumentRoot (usually `public_html`), for example:

- `/public_html/cs_webhooks/`

After that, this must **not** be accessible by URL:
- `https://site.com/cs_webhooks/...` (we will block it with a rule)

### Step B — Configure 2 keys + database
Open:

- `cs_webhooks/config/cryptostrazo.php`

Fill in **only**:
- `public_key` (cs_public_...)
- `secret` (cs_secret_...)

And choose your database:

#### Option 1: SQLite (no logins/passwords)
SQLite is a **file**. There are no logins/passwords. Access is controlled by server file permissions.

In the config set:
```php
'db' => [
  'driver' => 'sqlite',
  'sqlite_path' => __DIR__ . '/../storage/strz.sqlite',
]
```

Important: the `cs_webhooks/storage/` directory must be writable by the web server.

#### Option 2: MySQL/MariaDB (recommended)
MySQL is a **real database** on the server. You **must** provide login/password in the config.

In the config set:
```php
'db' => [
  'driver' => 'mysql',
  'host' => 'localhost',
  'name' => 'DB_NAME',
  'user' => 'DB_USER',
  'pass' => 'DB_PASS',
  'charset' => 'utf8mb4',
]
```

Important:
- you create the database and user in your hosting panel (or phpMyAdmin) **once**
- the client creates/migrates the `strz_webhook_inbox` table automatically on the first request (auto-migrate)

### Step C — Anti-replay window (`max_drift_seconds`) for retries

The receiver validates `X-STRZ-Timestamp` and rejects webhooks that are **too old**.  
This protects against replay attacks, but it also affects **retries**.

Recommended workflow:

1) **During setup / debugging**, temporarily increase the window so retries can still be accepted while you fix config/keys:
   - e.g. `86400` (24 hours) or `604800` (7 days)

2) **After you successfully receive the first webhook (or the first retry) and confirm everything works**, reduce it back to a strict value:
   - recommended: `300` (5 minutes)

Example:

```php
// Temporary for onboarding/debug (so retries don't expire by time)
'max_drift_seconds' => 86400, // 24h

// After everything works, switch to strict mode:
'max_drift_seconds' => 300,   // 5 min (default)
```

Notes:
- If your hosting queues/proxies can delay retries for a long time, you may keep a larger value (e.g. 1–24 hours).
- If the server time on the client side is wrong (no NTP), you will see: `401 timestamp_out_of_range`.

---

## 4) Required: add a rule to the root `.htaccess` (does not break the site)

### Where the root `.htaccess` is located
In the DocumentRoot of the site, typically:
- `/public_html/.htaccess`

### What to add
Insert this block **as high as possible** (ideally the first block in the file):

```apache
# ---- CryptoStrazo webhooks (isolated, does NOT affect other site routes) ----
RewriteEngine On

# 1) Block direct access to the internal folder by URL
RewriteRule ^cs_webhooks(/|$) - [F,L]

# 2) Publish ONLY the webhook endpoint (clean URL in site root)
RewriteRule ^webhooks/cryptostrazo/?$ cs_webhooks/public/index.php [L,QSA]

# 3) (optional) debug endpoint — enable only when needed
# RewriteRule ^webhooks/cryptostrazo/last/?$ cs_webhooks/public/index.php [L,QSA]
# ---- end ----
```

### Why it should be placed first
If your site runs WordPress/another CMS, it usually contains a rule like:
- “everything that is not a file/directory goes to index.php”

If that CMS block is **above** our block, then `/webhooks/cryptostrazo` will be handled by the CMS and the webhook client will never receive the request.

That’s why the webhook block should be placed at the top: it matches **only** `/webhooks/cryptostrazo` and does not affect anything else.

---

## 5) Webhook URL for the CryptoStrazo dashboard
Set the webhook URL to:
- `https://SITE.COM/webhooks/cryptostrazo`

---

## 6) Quick checks

### Check 1: Health endpoint (optional)
The receiver includes `GET /health`, but because we publish **only** `/webhooks/cryptostrazo`, it is not exposed by default.

If you want a temporary health check, add this rule **temporarily** (and remove it after testing):

```apache
RewriteRule ^health/?$ cs_webhooks/public/index.php [L,QSA]
```

Then open:
- `https://SITE.COM/health`

Expected response:
```json
{"ok":true}
```

### Check 2: Send a test webhook
Send a test webhook from the CryptoStrazo dashboard.  
After that, a record should appear in the database (SQLite or MySQL).

---

## 7) How to view stored data

### SQLite: file location
By default:
- `cs_webhooks/storage/strz.sqlite`

#### View via SSH
```bash
sqlite3 /path/to/public_html/cs_webhooks/storage/strz.sqlite \
"SELECT id, event, status, amount_total, currency, external_id, tx_id, received_at
 FROM strz_webhook_inbox ORDER BY id DESC LIMIT 20;"
```

Full JSON:
```bash
sqlite3 /path/to/public_html/cs_webhooks/storage/strz.sqlite \
"SELECT payload_json FROM strz_webhook_inbox ORDER BY id DESC LIMIT 1;"
```

#### View without SSH (locally on your PC)
1) Download `strz.sqlite` via SFTP
2) Open it using **DB Browser for SQLite** (or any SQLite viewer)

### MySQL/MariaDB: via phpMyAdmin
Latest rows:
```sql
SELECT id, event, status, amount_total, currency, external_id, tx_id, received_at
FROM strz_webhook_inbox
ORDER BY id DESC
LIMIT 20;
```

Full JSON:
```sql
SELECT payload_json
FROM strz_webhook_inbox
ORDER BY id DESC
LIMIT 1;
```

---

## 8) “Will it write to the DB without a password?”
Only in **SQLite** mode — SQLite has **no logins/passwords** because it is a file.

Security is ensured by:
- blocking URL access to `cs_webhooks/` via the root `.htaccess`
- storing the SQLite file on the server and relying on file-system permissions

In **MySQL** mode, a password is always required (configured in `cryptostrazo.php`).

---

## 9) Auto-creation (DB/table)

- **SQLite file** is created automatically on the first successful webhook (if `storage/` is writable).
- The **table** `strz_webhook_inbox` is created automatically (auto-migrate).
- **MySQL database and user** are NOT created automatically — you create them in the hosting panel.  
  The table will still be created automatically on the first request.

---

## 10) Folder permissions (typical safe setup)

If you have SSH:

```bash
cd /path/to/public_html

# folders
chmod 0755 cs_webhooks
find cs_webhooks -type d -exec chmod 0755 {} \;

# PHP files
find cs_webhooks -type f -name "*.php" -exec chmod 0644 {} \;

# storage for SQLite (if you use sqlite)
chmod 0755 cs_webhooks/storage
# sometimes shared hosting requires 0775 or 0777 — depends on the web-server user
```

If the SQLite file already exists:
```bash
chmod 0640 cs_webhooks/storage/strz.sqlite
```

---

## 11) Debug endpoint (optional)

Disabled by default.

Enable in `config/cryptostrazo.php`:
```php
'debug_ui' => true,
'debug_token' => 'change_me',
```

And (if needed) uncomment the rule in the root `.htaccess`:
```apache
RewriteRule ^webhooks/cryptostrazo/last/?$ cs_webhooks/public/index.php [L,QSA]
```

Test:
- `GET /webhooks/cryptostrazo/last?token=change_me`

Recommendation: enable only temporarily.

---

## 12) Multiple “plugins” on the same site (the right way)

Recommended approach:
- each module lives in its own folder:
  `cs_webhooks/`, `cs_orders/`, `cs_anything/`
- each module publishes **only its own** narrow rewrite rules in the root `.htaccess`

This prevents conflicts with CMS routing and with other modules.

---

## 13) Common errors

- `401 bad_signature` — wrong secret or something modifies the raw body (proxy/module)
- `401 timestamp_out_of_range` — incorrect server time (NTP not configured) or `max_drift_seconds` too strict for delayed retries
- `500 storage_failed` — unable to write to DB (permissions/disk space/DSN)
- `200 duplicate=true` — re-delivery (normal, webhooks are idempotent)
