<?php
declare(strict_types=1);

namespace CryptoStrazo\Client;

use PDO;
use PDOException;

final class Db
{
    private PDO $pdo;
    private string $driver;

    public function __construct(array $cfg)
    {
        $db = (array)($cfg['db'] ?? []);
        $driver = (string)($db['driver'] ?? 'auto');

        if ($driver === 'auto') {
            $hasMysql = ((string)($db['host'] ?? '') !== '')
                && ((string)($db['name'] ?? '') !== '')
                && ((string)($db['user'] ?? '') !== '');
            $driver = $hasMysql ? 'mysql' : 'sqlite';
        }

        $this->driver = $driver;

        if ($driver === 'mysql') {
            $host = (string)($db['host'] ?? '');
            $name = (string)($db['name'] ?? '');
            $user = (string)($db['user'] ?? '');
            $pass = (string)($db['pass'] ?? '');
            $charset = (string)($db['charset'] ?? 'utf8mb4');

            if ($host === '' || $name === '' || $user === '') {
                throw new \RuntimeException('Missing MySQL DB credentials in config.');
            }

            $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec("SET NAMES " . $this->pdo->quote($charset));
        } elseif ($driver === 'sqlite') {
            $path = (string)($db['sqlite_path'] ?? '');
            if ($path === '') {
                throw new \RuntimeException('Missing sqlite_path in config.');
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $dsn = "sqlite:" . $path;
            $this->pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $this->pdo->exec("PRAGMA journal_mode = WAL;");
            $this->pdo->exec("PRAGMA synchronous = NORMAL;");
        } else {
            throw new \RuntimeException('Unsupported db driver: ' . $driver);
        }

        $this->migrate();
    }

    private function mysqlColumnExists(string $table, string $column): bool
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) AS c
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $st->execute([':t' => $table, ':c' => $column]);
        $row = $st->fetch();
        return (int)($row['c'] ?? 0) > 0;
    }

    private function sqliteColumnExists(string $table, string $column): bool
    {
        $st = $this->pdo->query('PRAGMA table_info(' . $this->sqliteIdent($table) . ')');
        $cols = $st->fetchAll();
        foreach ($cols as $c) {
            if (($c['name'] ?? null) === $column) return true;
        }
        return false;
    }

    private function sqliteIdent(string $ident): string
    {
        // PRAGMA doesn't accept bind params; best-effort escaping.
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    public function migrate(): void
    {
        if ($this->driver === 'mysql') {
            // Base table for new installs
            $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS strz_webhook_inbox (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  delivery_uid      VARCHAR(80)     NOT NULL,
  event             VARCHAR(80)     NULL,
  received_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payload_json      LONGTEXT        NOT NULL,

  invoice_id        BIGINT          NULL,
  invoice_public_id VARCHAR(64)     NULL,
  external_id       VARCHAR(128)    NULL,
  kind              VARCHAR(32)     NULL,
  status            VARCHAR(32)     NULL,
  amount_base       VARCHAR(32)     NULL,
  amount_total      VARCHAR(32)     NULL,
  currency          VARCHAR(32)     NULL,
  wallet_address    VARCHAR(128)    NULL,
  network           VARCHAR(32)     NULL,
  tx_id             VARCHAR(128)    NULL,
  paid_at           VARCHAR(40)     NULL,
  expires_at        VARCHAR(40)     NULL,
  created_at        VARCHAR(40)     NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uk_delivery_uid (delivery_uid),
  KEY idx_event_received (event, received_at),
  KEY idx_external_id (external_id),
  KEY idx_invoice_public_id (invoice_public_id),
  KEY idx_tx_id (tx_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

            // Add missing columns for older installs (best-effort)
            $adds = [
                'invoice_id'        => "ALTER TABLE strz_webhook_inbox ADD COLUMN invoice_id BIGINT NULL",
                'invoice_public_id' => "ALTER TABLE strz_webhook_inbox ADD COLUMN invoice_public_id VARCHAR(64) NULL",
                'external_id'       => "ALTER TABLE strz_webhook_inbox ADD COLUMN external_id VARCHAR(128) NULL",
                'kind'              => "ALTER TABLE strz_webhook_inbox ADD COLUMN kind VARCHAR(32) NULL",
                'status'            => "ALTER TABLE strz_webhook_inbox ADD COLUMN status VARCHAR(32) NULL",
                'amount_base'       => "ALTER TABLE strz_webhook_inbox ADD COLUMN amount_base VARCHAR(32) NULL",
                'amount_total'      => "ALTER TABLE strz_webhook_inbox ADD COLUMN amount_total VARCHAR(32) NULL",
                'currency'          => "ALTER TABLE strz_webhook_inbox ADD COLUMN currency VARCHAR(32) NULL",
                'wallet_address'    => "ALTER TABLE strz_webhook_inbox ADD COLUMN wallet_address VARCHAR(128) NULL",
                'network'           => "ALTER TABLE strz_webhook_inbox ADD COLUMN network VARCHAR(32) NULL",
                'tx_id'             => "ALTER TABLE strz_webhook_inbox ADD COLUMN tx_id VARCHAR(128) NULL",
                'paid_at'           => "ALTER TABLE strz_webhook_inbox ADD COLUMN paid_at VARCHAR(40) NULL",
                'expires_at'        => "ALTER TABLE strz_webhook_inbox ADD COLUMN expires_at VARCHAR(40) NULL",
                'created_at'        => "ALTER TABLE strz_webhook_inbox ADD COLUMN created_at VARCHAR(40) NULL",
            ];
            foreach ($adds as $col => $sql) {
                try {
                    if (!$this->mysqlColumnExists('strz_webhook_inbox', $col)) {
                        $this->pdo->exec($sql);
                    }
                } catch (\Throwable $e) {
                    // ignore (shared hosting edge cases)
                }
            }

            // Indexes already in CREATE TABLE; older tables may miss them -> best-effort
            try { $this->pdo->exec("CREATE INDEX idx_external_id ON strz_webhook_inbox(external_id)"); } catch (\Throwable $e) {}
            try { $this->pdo->exec("CREATE INDEX idx_invoice_public_id ON strz_webhook_inbox(invoice_public_id)"); } catch (\Throwable $e) {}
            try { $this->pdo->exec("CREATE INDEX idx_tx_id ON strz_webhook_inbox(tx_id)"); } catch (\Throwable $e) {}
            try { $this->pdo->exec("CREATE INDEX idx_status ON strz_webhook_inbox(status)"); } catch (\Throwable $e) {}

            return;
        }

        // SQLite base table
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS strz_webhook_inbox (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  delivery_uid  TEXT NOT NULL UNIQUE,
  event         TEXT,
  received_at   TEXT NOT NULL DEFAULT (datetime('now')),
  payload_json  TEXT NOT NULL,

  invoice_id        INTEGER,
  invoice_public_id TEXT,
  external_id       TEXT,
  kind              TEXT,
  status            TEXT,
  amount_base       TEXT,
  amount_total      TEXT,
  currency          TEXT,
  wallet_address    TEXT,
  network           TEXT,
  tx_id             TEXT,
  paid_at           TEXT,
  expires_at        TEXT,
  created_at        TEXT
);
SQL);

        // Add missing columns for older installs (SQLite supports ADD COLUMN)
        $adds = [
            'invoice_id'        => "ALTER TABLE strz_webhook_inbox ADD COLUMN invoice_id INTEGER",
            'invoice_public_id' => "ALTER TABLE strz_webhook_inbox ADD COLUMN invoice_public_id TEXT",
            'external_id'       => "ALTER TABLE strz_webhook_inbox ADD COLUMN external_id TEXT",
            'kind'              => "ALTER TABLE strz_webhook_inbox ADD COLUMN kind TEXT",
            'status'            => "ALTER TABLE strz_webhook_inbox ADD COLUMN status TEXT",
            'amount_base'       => "ALTER TABLE strz_webhook_inbox ADD COLUMN amount_base TEXT",
            'amount_total'      => "ALTER TABLE strz_webhook_inbox ADD COLUMN amount_total TEXT",
            'currency'          => "ALTER TABLE strz_webhook_inbox ADD COLUMN currency TEXT",
            'wallet_address'    => "ALTER TABLE strz_webhook_inbox ADD COLUMN wallet_address TEXT",
            'network'           => "ALTER TABLE strz_webhook_inbox ADD COLUMN network TEXT",
            'tx_id'             => "ALTER TABLE strz_webhook_inbox ADD COLUMN tx_id TEXT",
            'paid_at'           => "ALTER TABLE strz_webhook_inbox ADD COLUMN paid_at TEXT",
            'expires_at'        => "ALTER TABLE strz_webhook_inbox ADD COLUMN expires_at TEXT",
            'created_at'        => "ALTER TABLE strz_webhook_inbox ADD COLUMN created_at TEXT",
        ];
        foreach ($adds as $col => $sql) {
            try {
                if (!$this->sqliteColumnExists('strz_webhook_inbox', $col)) {
                    $this->pdo->exec($sql);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Indexes (best-effort)
        try { $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_event_received ON strz_webhook_inbox(event, received_at)"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_external_id ON strz_webhook_inbox(external_id)"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_invoice_public_id ON strz_webhook_inbox(invoice_public_id)"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_id ON strz_webhook_inbox(tx_id)"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_status ON strz_webhook_inbox(status)"); } catch (\Throwable $e) {}
    }

    /**
     * Store verified webhook payload (RAW JSON string) + extracted invoice fields.
     * Returns:
     *  - ['ok'=>true, 'duplicate'=>false] on insert
     *  - ['ok'=>true, 'duplicate'=>true]  on duplicate delivery_uid
     *  - ['ok'=>false, 'error'=>...]      on failure
     */
    public function storeInbox(string $deliveryUid, ?string $event, string $rawJson, array $extracted = []): array
    {
        $deliveryUid = trim($deliveryUid);
        if ($deliveryUid === '') {
            $deliveryUid = 'missing_' . bin2hex(random_bytes(8));
        }

        // Normalize extracted
        $f = [
            'invoice_id'        => $extracted['invoice_id'] ?? null,
            'invoice_public_id' => $extracted['invoice_public_id'] ?? null,
            'external_id'       => $extracted['external_id'] ?? null,
            'kind'              => $extracted['kind'] ?? null,
            'status'            => $extracted['status'] ?? null,
            'amount_base'       => $extracted['amount_base'] ?? null,
            'amount_total'      => $extracted['amount_total'] ?? null,
            'currency'          => $extracted['currency'] ?? null,
            'wallet_address'    => $extracted['wallet_address'] ?? null,
            'network'           => $extracted['network'] ?? null,
            'tx_id'             => $extracted['tx_id'] ?? null,
            'paid_at'           => $extracted['paid_at'] ?? null,
            'expires_at'        => $extracted['expires_at'] ?? null,
            'created_at'        => $extracted['created_at'] ?? null,
        ];

        try {
            if ($this->driver === 'mysql') {
                $sql = "INSERT INTO strz_webhook_inbox
                        (delivery_uid, event, payload_json,
                         invoice_id, invoice_public_id, external_id, kind, status, amount_base, amount_total, currency,
                         wallet_address, network, tx_id, paid_at, expires_at, created_at)
                        VALUES
                        (:d, :e, :p,
                         :invoice_id, :invoice_public_id, :external_id, :kind, :status, :amount_base, :amount_total, :currency,
                         :wallet_address, :network, :tx_id, :paid_at, :expires_at, :created_at)";
                $st = $this->pdo->prepare($sql);
                $st->execute([
                    ':d' => $deliveryUid,
                    ':e' => $event,
                    ':p' => $rawJson,
                    ':invoice_id' => $f['invoice_id'],
                    ':invoice_public_id' => $f['invoice_public_id'],
                    ':external_id' => $f['external_id'],
                    ':kind' => $f['kind'],
                    ':status' => $f['status'],
                    ':amount_base' => $f['amount_base'],
                    ':amount_total' => $f['amount_total'],
                    ':currency' => $f['currency'],
                    ':wallet_address' => $f['wallet_address'],
                    ':network' => $f['network'],
                    ':tx_id' => $f['tx_id'],
                    ':paid_at' => $f['paid_at'],
                    ':expires_at' => $f['expires_at'],
                    ':created_at' => $f['created_at'],
                ]);
                return ['ok' => true, 'duplicate' => false];
            }

            // SQLite: INSERT OR IGNORE
            $sql = "INSERT OR IGNORE INTO strz_webhook_inbox
                    (delivery_uid, event, payload_json,
                     invoice_id, invoice_public_id, external_id, kind, status, amount_base, amount_total, currency,
                     wallet_address, network, tx_id, paid_at, expires_at, created_at)
                    VALUES
                    (:d, :e, :p,
                     :invoice_id, :invoice_public_id, :external_id, :kind, :status, :amount_base, :amount_total, :currency,
                     :wallet_address, :network, :tx_id, :paid_at, :expires_at, :created_at)";
            $st = $this->pdo->prepare($sql);
            $st->execute([
                ':d' => $deliveryUid,
                ':e' => $event,
                ':p' => $rawJson,
                ':invoice_id' => $f['invoice_id'],
                ':invoice_public_id' => $f['invoice_public_id'],
                ':external_id' => $f['external_id'],
                ':kind' => $f['kind'],
                ':status' => $f['status'],
                ':amount_base' => $f['amount_base'],
                ':amount_total' => $f['amount_total'],
                ':currency' => $f['currency'],
                ':wallet_address' => $f['wallet_address'],
                ':network' => $f['network'],
                ':tx_id' => $f['tx_id'],
                ':paid_at' => $f['paid_at'],
                ':expires_at' => $f['expires_at'],
                ':created_at' => $f['created_at'],
            ]);

            if ($st->rowCount() === 0) {
                return ['ok' => true, 'duplicate' => true];
            }
            return ['ok' => true, 'duplicate' => false];

        } catch (PDOException $e) {
            $sqlState = (string)$e->getCode();
            $msg = $e->getMessage();

            if ($this->driver === 'mysql' && $sqlState === '23000' && str_contains($msg, '1062')) {
                return ['ok' => true, 'duplicate' => true];
            }
            return ['ok' => false, 'error' => 'db_error', 'detail' => $msg];
        }
    }

    public function fetchLastInbox(): ?array
    {
        $st = $this->pdo->query("SELECT * FROM strz_webhook_inbox ORDER BY id DESC LIMIT 1");
        $row = $st->fetch();
        return $row ?: null;
    }
}
