<?php
declare(strict_types=1);

function jpm_schema_is_ready(PDO $pdo): bool
{
    if (($GLOBALS['jpm_schema_ready'] ?? null) === true) return true;
    if (($GLOBALS['jpm_schema_ready'] ?? null) === false) return false;
    try {
        $stored = function_exists('settings_get')
            ? (string)(settings_get($pdo, 'jpm_schema_version', '') ?? '')
            : '';
        if ($stored !== JPM_SCHEMA_VERSION) return $GLOBALS['jpm_schema_ready'] = false;
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([JPM_CAMPAIGNS_TABLE]);
        return $GLOBALS['jpm_schema_ready'] = ((int)$stmt->fetchColumn() === 1);
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
                `frequency` VARCHAR(32) NOT NULL DEFAULT \'session\',
                `delay_ms` INT UNSIGNED NOT NULL DEFAULT 500,
                `max_width` SMALLINT UNSIGNED NOT NULL DEFAULT 720,
                `show_close` TINYINT(1) NOT NULL DEFAULT 1,
                `close_on_overlay` TINYINT(1) NOT NULL DEFAULT 1,
                `priority` SMALLINT NOT NULL DEFAULT 0,
                `starts_at` DATETIME DEFAULT NULL,
                `ends_at` DATETIME DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_jpm_campaigns_runtime` (`status`, `starts_at`, `ends_at`, `priority`),
                KEY `idx_jpm_campaigns_updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $verify = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND COLUMN_NAME IN (\'content_type\', \'target_mode\', \'frequency\', \'updated_at\')'
        );
        $verify->execute([JPM_CAMPAIGNS_TABLE]);
        if ((int)$verify->fetchColumn() !== 4) throw new RuntimeException('Popup Manager schema verification failed.');
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
    $pdo->exec('DROP TABLE IF EXISTS `' . JPM_CAMPAIGNS_TABLE . '`');
    $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute(['jpm_schema_version']);
    $GLOBALS['jpm_schema_ready'] = false;
}
