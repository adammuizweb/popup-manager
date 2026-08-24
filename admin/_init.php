<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    adiwira_render_404();
    return;
}
[$jpmUserId] = adiwira_require_permission($pdo, JPM_PERMISSION, false);
if (!jpm_ensure_schema($pdo)) {
    throw new RuntimeException(jpm_t('Popup Manager storage is not available.'));
}
$jpmBase = rtrim((string)ADMIN_BASE_PATH, '/') . '/?page=admin/tools/popup-manager';
