<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (!function_exists('adiwira_redirect_with_flash') && defined('DASH_PATH')) {
    require_once rtrim((string)DASH_PATH, DIRECTORY_SEPARATOR) . '/admin/_notify.php';
}
$redirect = static function (string $type, string $message) use ($jpmBase): never {
    if (function_exists('adiwira_redirect_with_flash')) adiwira_redirect_with_flash($jpmBase, $type, $message, 303);
    header('Location: ' . $jpmBase, true, 303);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    adiwira_render_404();
    return;
}
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    $redirect('error', jpm_t('The security token is invalid. Please try again.'));
}
$id = max(0, (int)($_POST['id'] ?? 0));
try {
    if ($id <= 0) throw new DomainException(jpm_t('Popup campaign not found.'));
    $pdo->beginTransaction();
    if (function_exists('authorization_lock_actor_permissions') && !authorization_lock_actor_permissions($pdo, $jpmUserId)) throw new RuntimeException('Unable to lock actor permissions.');
    if (function_exists('user_can') && !user_can($pdo, $jpmUserId, JPM_PERMISSION)) throw new RuntimeException('Campaign permission changed.');
    $campaign = jpm_campaign($pdo, $id, true);
    if (!is_array($campaign)) throw new DomainException(jpm_t('Popup campaign not found.'));
    $pdo->prepare('DELETE FROM `' . JPM_CAMPAIGNS_TABLE . '` WHERE id = ?')->execute([$id]);
    if (function_exists('authorization_audit') && !authorization_audit($pdo, 'jpm.campaign.deleted', $jpmUserId, null, 'popup-manager', (string)$id, ['name' => $campaign['name']])) throw new RuntimeException('Unable to write audit event.');
    $pdo->commit();
    $redirect('success', jpm_t('Popup campaign deleted.'));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!$error instanceof DomainException) error_log('[popup-manager] delete error: ' . $error->getMessage());
    $redirect('error', $error instanceof DomainException ? $error->getMessage() : jpm_t('The campaign could not be deleted.'));
}
