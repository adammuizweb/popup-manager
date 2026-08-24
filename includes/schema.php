<?php
declare(strict_types=1);

function jpm_schema_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() === 1;
}

function jpm_schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() === 1;
}

function jpm_schema_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function jpm_schema_is_ready(PDO $pdo): bool
{
    if (($GLOBALS['jpm_schema_ready'] ?? null) === true) return true;
    if (($GLOBALS['jpm_schema_ready'] ?? null) === false) return false;
    try {
        $stored = function_exists('settings_get') ? (string)(settings_get($pdo, 'jpm_schema_version', '') ?? '') : '';
        if ($stored !== JPM_SCHEMA_VERSION || !jpm_schema_table_exists($pdo, JPM_CAMPAIGNS_TABLE)
            || !jpm_schema_table_exists($pdo, JPM_EVENT_TOKENS_TABLE)) {
            return $GLOBALS['jpm_schema_ready'] = false;
        }
        foreach (['html_policy', 'context_rules', 'sequence_order', 'impression_count', 'close_count', 'click_count'] as $column) {
            if (!jpm_schema_column_exists($pdo, JPM_CAMPAIGNS_TABLE, $column)) return $GLOBALS['jpm_schema_ready'] = false;
        }
        return $GLOBALS['jpm_schema_ready'] = true;
    } catch (Throwable $error) {
        error_log('[popup-manager] schema readiness error: ' . $error->getMessage());
        return $GLOBALS['jpm_schema_ready'] = false;
    }
}

function jpm_ensure_schema(PDO $pdo): bool
{
    if (jpm_schema_is_ready($pdo)) return true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . JPM_CAMPAIGNS_TABLE . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(190) NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT \'draft\',
                `content_type` VARCHAR(16) NOT NULL DEFAULT \'image\',
                `html_policy` VARCHAR(16) NOT NULL DEFAULT \'restricted\',
                `html_content` MEDIUMTEXT DEFAULT NULL,
                `desktop_media_id` INT UNSIGNED DEFAULT NULL,
                `tablet_media_id` INT UNSIGNED DEFAULT NULL,
                `mobile_media_id` INT UNSIGNED DEFAULT NULL,
                `image_alt` VARCHAR(255) DEFAULT NULL,
                `target_url` VARCHAR(2048) DEFAULT NULL,
                `open_new_tab` TINYINT(1) NOT NULL DEFAULT 0,
                `target_mode` VARCHAR(16) NOT NULL DEFAULT \'all\',
                `include_rules` TEXT DEFAULT NULL,
                `exclude_rules` TEXT DEFAULT NULL,
                `context_rules` TEXT DEFAULT NULL,
                `frequency` VARCHAR(32) NOT NULL DEFAULT \'session\',
                `delay_ms` INT UNSIGNED NOT NULL DEFAULT 500,
                `max_width` SMALLINT UNSIGNED NOT NULL DEFAULT 720,
                `show_close` TINYINT(1) NOT NULL DEFAULT 1,
                `close_on_overlay` TINYINT(1) NOT NULL DEFAULT 1,
                `priority` SMALLINT NOT NULL DEFAULT 0,
                `sequence_order` INT UNSIGNED NOT NULL DEFAULT 100,
                `impression_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `close_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `click_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `last_event_at` DATETIME DEFAULT NULL,
                `starts_at` DATETIME DEFAULT NULL,
                `ends_at` DATETIME DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_jpm_campaigns_runtime` (`status`, `starts_at`, `ends_at`, `sequence_order`, `priority`),
                KEY `idx_jpm_campaigns_updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $columns = [
            'html_policy' => 'VARCHAR(16) NOT NULL DEFAULT \'restricted\' AFTER `content_type`',
            'context_rules' => 'TEXT DEFAULT NULL AFTER `exclude_rules`',
            'sequence_order' => 'INT UNSIGNED NOT NULL DEFAULT 100 AFTER `priority`',
            'impression_count' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `sequence_order`',
            'close_count' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `impression_count`',
            'click_count' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `close_count`',
            'last_event_at' => 'DATETIME DEFAULT NULL AFTER `click_count`',
        ];
        $addedSequence = false;
        foreach ($columns as $name => $definition) {
            if (jpm_schema_column_exists($pdo, JPM_CAMPAIGNS_TABLE, $name)) continue;
            $pdo->exec('ALTER TABLE `' . JPM_CAMPAIGNS_TABLE . '` ADD COLUMN `' . $name . '` ' . $definition);
            if ($name === 'sequence_order') $addedSequence = true;
        }

        if ($addedSequence) {
            $ids = $pdo->query('SELECT id FROM `' . JPM_CAMPAIGNS_TABLE . '` ORDER BY priority DESC, updated_at DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $updateOrder = $pdo->prepare('UPDATE `' . JPM_CAMPAIGNS_TABLE . '` SET sequence_order = ? WHERE id = ?');
            foreach ($ids as $index => $id) $updateOrder->execute([($index + 1) * 10, (int)$id]);
        }
        if (!jpm_schema_index_exists($pdo, JPM_CAMPAIGNS_TABLE, 'idx_jpm_campaigns_queue')) {
            $pdo->exec('ALTER TABLE `' . JPM_CAMPAIGNS_TABLE . '` ADD INDEX `idx_jpm_campaigns_queue` (`status`, `sequence_order`, `priority`)');
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . JPM_EVENT_TOKENS_TABLE . '` (
                `token_hash` CHAR(64) NOT NULL,
                `campaign_id` BIGINT UNSIGNED NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `impression_counted` TINYINT(1) NOT NULL DEFAULT 0,
                `close_counted` TINYINT(1) NOT NULL DEFAULT 0,
                `click_counted` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`token_hash`),
                KEY `idx_jpm_event_tokens_expiry` (`expires_at`),
                KEY `idx_jpm_event_tokens_campaign` (`campaign_id`),
                CONSTRAINT `fk_jpm_event_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `' . JPM_CAMPAIGNS_TABLE . '` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        foreach (array_keys($columns) as $column) {
            if (!jpm_schema_column_exists($pdo, JPM_CAMPAIGNS_TABLE, $column)) throw new RuntimeException('Popup Manager schema verification failed.');
        }
        if (!jpm_schema_table_exists($pdo, JPM_EVENT_TOKENS_TABLE)) throw new RuntimeException('Popup Manager event schema verification failed.');
        if (function_exists('settings_set') && !settings_set($pdo, 'jpm_schema_version', JPM_SCHEMA_VERSION, 1)) {
            throw new RuntimeException('Unable to persist the Popup Manager schema version.');
        }
        $GLOBALS['jpm_schema_ready'] = true;
        return true;
    } catch (Throwable $error) {
        $GLOBALS['jpm_schema_ready'] = false;
        error_log('[popup-manager] schema installation error: ' . $error->getMessage());
        return false;
    }
}

function jpm_drop_schema(PDO $pdo): void
{
    $pdo->exec('DROP TABLE IF EXISTS `' . JPM_EVENT_TOKENS_TABLE . '`');
    $pdo->exec('DROP TABLE IF EXISTS `' . JPM_CAMPAIGNS_TABLE . '`');
    $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute(['jpm_schema_version']);
    $GLOBALS['jpm_schema_ready'] = false;
}
