<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (!function_exists('adiwira_redirect_with_flash') && defined('DASH_PATH')) {
    require_once rtrim((string)DASH_PATH, DIRECTORY_SEPARATOR) . '/admin/_notify.php';
}
$return = [];
$search = mb_substr(trim((string)($_POST['return_search'] ?? '')), 0, 100, 'UTF-8');
if ($search !== '') $return['search'] = $search;
foreach (['status' => ['draft', 'active', 'paused'], 'type' => ['image', 'html'], 'target' => ['all', 'homepage', 'paths', 'contexts']] as $key => $allowed) {
    $value = (string)($_POST['return_' . $key] ?? '');
    if (in_array($value, $allowed, true)) $return[$key] = $value;
}
$page = max(1, (int)($_POST['return_page'] ?? 1));
if ($page > 1) $return['p'] = $page;
$suffix = $return === [] ? '' : '&' . http_build_query($return);
$redirect = static function (string $type, string $message) use ($jpmBase, $suffix): never {
    $location = $jpmBase . $suffix;
    if (function_exists('adiwira_redirect_with_flash')) adiwira_redirect_with_flash($location, $type, $message, 303);
    header('Location: ' . $location, true, 303);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    adiwira_render_404();
    return;
}
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    $redirect('error', jpm_t('The security token is invalid. Please try again.'));
}
$bulkAction = (string)($_POST['bulk_action'] ?? '');
if (!in_array($bulkAction, ['activate', 'pause', 'draft', 'delete'], true)) {
    $redirect('error', jpm_t('Select a valid bulk action.'));
}
$rawIds = is_array($_POST['campaign_ids'] ?? null) ? $_POST['campaign_ids'] : [];
$ids = [];
foreach (array_slice($rawIds, 0, 100) as $rawId) {
    if (!is_scalar($rawId)) continue;
    $id = (int)$rawId;
    if ($id > 0) $ids[$id] = true;
}
$ids = array_keys($ids);
if ($ids === []) $redirect('error', jpm_t('Select at least one campaign.'));

try {
    $pdo->beginTransaction();
    if (function_exists('authorization_lock_actor_permissions') && !authorization_lock_actor_permissions($pdo, $jpmUserId)) throw new RuntimeException('Unable to lock actor permissions.');
    if (function_exists('user_can') && !user_can($pdo, $jpmUserId, JPM_PERMISSION)) throw new RuntimeException('Campaign permission changed.');
    $changed = [];
    foreach ($ids as $id) {
        $campaign = jpm_campaign($pdo, $id, true);
        if (!is_array($campaign)) continue;
        if ($bulkAction === 'delete') {
            $pdo->prepare('DELETE FROM `' . JPM_CAMPAIGNS_TABLE . '` WHERE id=?')->execute([$id]);
        } else {
            $status = ['activate' => 'active', 'pause' => 'paused', 'draft' => 'draft'][$bulkAction];
            $pdo->prepare('UPDATE `' . JPM_CAMPAIGNS_TABLE . '` SET status=?,updated_by=? WHERE id=?')->execute([$status, $jpmUserId, $id]);
        }
        $changed[] = $id;
    }
    if ($changed === []) throw new DomainException(jpm_t('No matching campaigns were changed.'));
    if (function_exists('authorization_audit') && !authorization_audit($pdo, 'jpm.campaign.bulk_changed', $jpmUserId, null, 'popup-manager', null, ['action' => $bulkAction, 'ids' => $changed])) throw new RuntimeException('Unable to write audit event.');
    $pdo->commit();
    $redirect('success', jpm_t('%d campaigns updated.', count($changed)));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!$error instanceof DomainException) error_log('[popup-manager] bulk error: ' . $error->getMessage());
    $redirect('error', $error instanceof DomainException ? $error->getMessage() : jpm_t('The campaigns could not be updated.'));
}
