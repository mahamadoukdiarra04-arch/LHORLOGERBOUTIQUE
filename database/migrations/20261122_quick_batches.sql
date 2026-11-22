-- Also applied lazily by ensure_accounting_schema() before any transaction.
CREATE TABLE IF NOT EXISTS accounting_quick_batches (
  idempotency_key CHAR(36) NOT NULL PRIMARY KEY,
  payload_hash CHAR(64) NOT NULL,
  result_json LONGTEXT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS order_archives (
  order_ref VARCHAR(32) NOT NULL PRIMARY KEY,
  reason VARCHAR(500) NOT NULL,
  archived_by_user_id BIGINT UNSIGNED NULL,
  archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  restored_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
