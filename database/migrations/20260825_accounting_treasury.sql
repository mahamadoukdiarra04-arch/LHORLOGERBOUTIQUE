-- L’Horloger — Comptabilité & trésorerie, phase 1
--
-- This migration is applied by the versioned PHP runner
-- app/accounting.php::ensure_accounting_schema(). The runner is deliberately
-- used on existing Hostinger databases because it can inspect legacy columns,
-- add them only when absent, acquire a MySQL lock and stamp the version only
-- after all tables and system categories are ready.
--
-- Deployment procedure:
-- 1. Deploy the PHP source containing this migration and app/accounting.php.
-- 2. Sign in as a manager and open /admin/accounting.php once.
-- 3. Confirm one row in accounting_schema_migrations:
--      20260825_accounting_foundation
-- 4. Confirm that accounting_accounts remains empty until real opening
--    balances are entered in the next phase.
--
-- The equivalent clean-install DDL lives in ../schema.sql. Do not run a
-- partial manual ALTER from this file in production: the protected PHP runner
-- is the atomic, idempotent migration path for this application.

CREATE TABLE IF NOT EXISTS accounting_schema_migrations (
  version VARCHAR(80) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
