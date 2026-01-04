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

CREATE INDEX IF NOT EXISTS idx_event_received ON strz_webhook_inbox(event, received_at);
CREATE INDEX IF NOT EXISTS idx_external_id ON strz_webhook_inbox(external_id);
CREATE INDEX IF NOT EXISTS idx_invoice_public_id ON strz_webhook_inbox(invoice_public_id);
CREATE INDEX IF NOT EXISTS idx_tx_id ON strz_webhook_inbox(tx_id);
CREATE INDEX IF NOT EXISTS idx_status ON strz_webhook_inbox(status);
