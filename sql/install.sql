-- Kronixon Connect: module tables (Spec §4.3, §4.4, §5.5, §6.24, §6.26, §7.3, §11).
-- PREFIX_ and ENGINE_TYPE are replaced at install (_DB_PREFIX_, _MYSQL_ENGINE_). Indexed text columns
-- stay at 191 characters (utf8mb4 index limit of MySQL 5.6 / COMPACT rows). Created by M0, written by:
--   kx_nonce, kx_idempotency, kx_log  → M1 (signatures, rotate/unpair, logs)
--   kx_entity_map                     → read from M2 (kx_id in GET catalog/*), written from M3
--   kx_pending                        → M3 (what an item that is not complete has created)
--   kx_redirect                       → M3 (seo/redirects)
--   kx_image_map                      → M4 (POST/DELETE images)
--   kx_bulk_chunk                     → M4 (prices/bulk, stock/bulk: a chunk's marker, never committed)
--   kx_outbox                         → M5 (store events, heartbeat; reshaped in 0.6.0)

CREATE TABLE IF NOT EXISTS `PREFIX_kx_nonce` (
  `nonce` CHAR(36) NOT NULL,
  `seen_at` DATETIME NOT NULL,
  PRIMARY KEY (`nonce`),
  KEY `ix_seen_at` (`seen_at`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_kx_idempotency` (
  `idempotency_key` CHAR(36) NOT NULL,
  `request_sha256` CHAR(64) NOT NULL,
  `response_json` MEDIUMTEXT NOT NULL,
  `status_code` SMALLINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`idempotency_key`),
  KEY `ix_created_at` (`created_at`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_kx_log` (
  `id_kx_log` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` VARCHAR(10) NOT NULL,
  `channel` VARCHAR(32) NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `context` TEXT NULL,
  `request_id` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_kx_log`),
  KEY `ix_created_at` (`created_at`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_kx_entity_map` (
  `id_kx_entity_map` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` VARCHAR(32) NOT NULL,
  `external_id` VARCHAR(64) NOT NULL,
  `kx_id` CHAR(36) NOT NULL,
  `flags` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'bit 1 = hidden_by_kx (stock/bulk visibility_policy)',
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_kx_entity_map`),
  UNIQUE KEY `uq_entity` (`entity_type`, `external_id`),
  UNIQUE KEY `uq_kx_id` (`kx_id`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_kx_pending` (
  `id_kx_pending` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_shop` INT UNSIGNED NOT NULL,
  `class` VARCHAR(64) NOT NULL,
  `entity` VARCHAR(64) NOT NULL COMMENT 'table of the object, without prefix',
  `id_object` INT UNSIGNED NOT NULL,
  `kx_id` VARCHAR(36) NOT NULL DEFAULT '' COMMENT 'of the item; empty without one',
  `item_key` VARCHAR(64) NOT NULL DEFAULT '',
  `removing` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 = its removal began',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_kx_pending`),
  KEY `ix_object` (`id_shop`, `entity`, `id_object`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_kx_image_map` (
  `id_image` INT UNSIGNED NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `kx_key` VARCHAR(64) NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `source_url_host` VARCHAR(191) NULL,
  `uploaded_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_image`),
  KEY `ix_product_key` (`id_product`, `kx_key`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_kx_redirect` (
  `id_kx_redirect` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_shop` INT UNSIGNED NOT NULL,
  `path_from` VARBINARY(764) NOT NULL COMMENT 'UTF-8, at most 191 characters, compared byte for byte as the router does',
  `target_type` VARCHAR(16) NOT NULL,
  `target_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `code` SMALLINT UNSIGNED NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_kx_redirect`),
  UNIQUE KEY `uq_path` (`id_shop`, `path_from`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

-- A bulk chunk writes its row first and deletes it last, before COMMIT: a row that is gone by then means
-- the server rolled the chunk's transaction back while it ran (Database::commitChunk()). Always empty.
CREATE TABLE IF NOT EXISTS `PREFIX_kx_bulk_chunk` (
  `id_kx_bulk_chunk` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id_kx_bulk_chunk`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

-- One row per change a hook saw (Outbox\Recorder). Rows are only ever inserted by a hook, so a checkout
-- never waits for a lock on another row. Rows with the same entity_key are sent as one event. A batch
-- claims its rows by primary key (batch_id), and a batch that was cut short is sent again unchanged.
-- occurred_at is UTC with milliseconds.
CREATE TABLE IF NOT EXISTS `PREFIX_kx_outbox` (
  `id_outbox` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` CHAR(36) NOT NULL,
  `type` VARCHAR(48) NOT NULL,
  `external_id` VARCHAR(64) NULL,
  `entity_key` VARCHAR(191) NOT NULL,
  `payload` TEXT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  `status` ENUM('pending', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  `batch_id` CHAR(36) NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id_outbox`),
  UNIQUE KEY `uq_event` (`event_id`),
  KEY `ix_status` (`status`, `id_outbox`),
  KEY `ix_batch` (`batch_id`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;
