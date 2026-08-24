<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (!function_exists('adiwira_redirect_with_flash') && defined('DASH_PATH')) {
    require_once rtrim((string)DASH_PATH, DIRECTORY_SEPARATOR) . '/admin/_notify.php';
}
$redirect = static function (string $type, string $message, string $suffix = '') use ($jpmBase): never {
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

$action = (string)($_POST['action'] ?? 'save');
$id = max(0, (int)($_POST['id'] ?? 0));
try {
    if ($action === 'move') {
        $direction = (string)($_POST['direction'] ?? '');
        if (!in_array($direction, ['up', 'down'], true) || $id <= 0) throw new DomainException(jpm_t('Invalid campaign order request.'));
        $pdo->beginTransaction();
        if (function_exists('authorization_lock_actor_permissions') && !authorization_lock_actor_permissions($pdo, $jpmUserId)) throw new RuntimeException('Unable to lock actor permissions.');
        if (function_exists('user_can') && !user_can($pdo, $jpmUserId, JPM_PERMISSION)) throw new RuntimeException('Campaign permission changed.');
        $rows = $pdo->query('SELECT id FROM `' . JPM_CAMPAIGNS_TABLE . '` ORDER BY sequence_order ASC, priority DESC, id ASC FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $ids = array_map('intval', $rows);
        $index = array_search($id, $ids, true);
        if ($index === false) throw new DomainException(jpm_t('Popup campaign not found.'));
        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if (isset($ids[$targetIndex])) [$ids[$index], $ids[$targetIndex]] = [$ids[$targetIndex], $ids[$index]];
        $orderStmt = $pdo->prepare('UPDATE `' . JPM_CAMPAIGNS_TABLE . '` SET sequence_order=?,updated_by=? WHERE id=?');
        foreach ($ids as $position => $campaignId) $orderStmt->execute([($position + 1) * 10, $jpmUserId, $campaignId]);
        if (function_exists('authorization_audit') && !authorization_audit($pdo, 'jpm.campaign.reordered', $jpmUserId, null, 'popup-manager', (string)$id, ['direction' => $direction])) throw new RuntimeException('Unable to write audit event.');
        $pdo->commit();
        $redirect('success', jpm_t('Campaign order updated.'));
    }

    if ($action === 'toggle') {
        $status = (string)($_POST['status'] ?? 'paused');
        if (!in_array($status, ['active', 'paused'], true) || $id <= 0) throw new DomainException(jpm_t('Invalid campaign status request.'));
        $pdo->beginTransaction();
        if (function_exists('authorization_lock_actor_permissions') && !authorization_lock_actor_permissions($pdo, $jpmUserId)) throw new RuntimeException('Unable to lock actor permissions.');
        if (function_exists('user_can') && !user_can($pdo, $jpmUserId, JPM_PERMISSION)) throw new RuntimeException('Campaign permission changed.');
        $before = jpm_campaign($pdo, $id, true);
        if (!is_array($before)) throw new DomainException(jpm_t('Popup campaign not found.'));
        $pdo->prepare('UPDATE `' . JPM_CAMPAIGNS_TABLE . '` SET status = ?, updated_by = ? WHERE id = ?')->execute([$status, $jpmUserId, $id]);
        if (function_exists('authorization_audit') && !authorization_audit($pdo, 'jpm.campaign.status_changed', $jpmUserId, null, 'popup-manager', (string)$id, ['before' => $before['status'], 'after' => $status])) throw new RuntimeException('Unable to write audit event.');
        $pdo->commit();
        $redirect('success', jpm_t('Campaign status updated.'));
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 190) throw new DomainException(jpm_t('Enter a campaign name up to 190 characters.'));
    $status = (string)($_POST['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'active', 'paused'], true)) throw new DomainException(jpm_t('Select a valid campaign status.'));
    $contentType = (string)($_POST['content_type'] ?? 'image');
    if (!in_array($contentType, ['image', 'html'], true)) throw new DomainException(jpm_t('Select a valid content type.'));
    $htmlPolicy = (string)($_POST['html_policy'] ?? 'restricted');
    if (!in_array($htmlPolicy, ['restricted', 'trusted'], true)) throw new DomainException(jpm_t('Select a valid HTML policy.'));
    if ($contentType !== 'html') $htmlPolicy = 'restricted';
    $canTrustedHtml = function_exists('user_can') && user_can($pdo, $jpmUserId, JPM_TRUSTED_HTML_PERMISSION);
    if ($htmlPolicy === 'trusted' && !$canTrustedHtml) throw new DomainException(jpm_t('Trusted Embed HTML is restricted to the Site Owner.'));
    $targetMode = (string)($_POST['target_mode'] ?? 'all');
    if (!in_array($targetMode, ['all', 'homepage', 'paths', 'contexts'], true)) throw new DomainException(jpm_t('Select a valid targeting mode.'));
    $frequency = (string)($_POST['frequency'] ?? 'session');
    if (!in_array($frequency, ['every_view', 'session', 'path_session', 'visitor', 'daily'], true)) throw new DomainException(jpm_t('Select a valid display frequency.'));

    $html = jpm_sanitize_html((string)($_POST['html_content'] ?? ''), $htmlPolicy);
    $desktopId = max(0, (int)($_POST['desktop_media_id'] ?? 0));
    $tabletId = max(0, (int)($_POST['tablet_media_id'] ?? 0));
    $mobileId = max(0, (int)($_POST['mobile_media_id'] ?? 0));
    if ($contentType === 'html' && !jpm_html_has_content($html)) throw new DomainException(jpm_t('Enter HTML content for this campaign.'));
    if ($contentType === 'image') {
        if (!jpm_public_media($pdo, $desktopId)) throw new DomainException(jpm_t('Choose a public desktop image.'));
        foreach ([$tabletId, $mobileId] as $optionalMediaId) {
            if ($optionalMediaId > 0 && !jpm_public_media($pdo, $optionalMediaId)) throw new DomainException(jpm_t('One of the optional responsive images is not public or no longer exists.'));
        }
    }

    $targetUrl = jpm_normalize_target_url((string)($_POST['target_url'] ?? ''));
    if ($targetUrl === null) throw new DomainException(jpm_t('Enter a valid root-relative or HTTP(S) destination URL.'));
    $includes = jpm_rules_from_text((string)($_POST['include_rules'] ?? ''));
    $excludes = jpm_rules_from_text((string)($_POST['exclude_rules'] ?? ''));
    $contexts = jpm_context_rules_from_input($_POST['context_rules'] ?? []);
    if ($targetMode === 'paths' && $includes === []) throw new DomainException(jpm_t('Add at least one included path for selected-path targeting.'));
    if ($targetMode === 'contexts' && $contexts === []) throw new DomainException(jpm_t('Select at least one page type.'));

    $startsAt = jpm_parse_datetime((string)($_POST['starts_at'] ?? ''));
    $endsAt = jpm_parse_datetime((string)($_POST['ends_at'] ?? ''));
    if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) throw new DomainException(jpm_t('The end date must be later than the start date.'));
    $delaySeconds = filter_var($_POST['delay_seconds'] ?? 0.5, FILTER_VALIDATE_FLOAT);
    if ($delaySeconds === false || $delaySeconds < 0 || $delaySeconds > 60) throw new DomainException(jpm_t('Delay must be between 0 and 60 seconds.'));
    $delayMs = (int)round($delaySeconds * 1000);
    $maxWidth = (int)($_POST['max_width'] ?? 720);
    if ($maxWidth < 320 || $maxWidth > 1200) throw new DomainException(jpm_t('Maximum width must be between 320 and 1200 pixels.'));
    $priority = (int)($_POST['priority'] ?? 0);
    if ($priority < -1000 || $priority > 1000) throw new DomainException(jpm_t('Priority must be between -1000 and 1000.'));
    $sequenceOrder = filter_var($_POST['sequence_order'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    if ($sequenceOrder === false) throw new DomainException(jpm_t('Queue order must be between 1 and 100000.'));
    $showClose = !empty($_POST['show_close']) ? 1 : 0;
    $closeOverlay = !empty($_POST['close_on_overlay']) ? 1 : 0;
    if ($showClose === 0 && $closeOverlay === 0) throw new DomainException(jpm_t('Keep at least one visible close method enabled.'));

    $values = [
        $name, $status, $contentType, $htmlPolicy, $html === '' ? null : $html,
        $desktopId ?: null, $tabletId ?: null, $mobileId ?: null,
        mb_substr(trim((string)($_POST['image_alt'] ?? '')), 0, 255, 'UTF-8'),
        $targetUrl === '' ? null : $targetUrl, !empty($_POST['open_new_tab']) ? 1 : 0,
        $targetMode, json_encode($includes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($excludes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($contexts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $frequency, $delayMs, $maxWidth, $showClose, $closeOverlay, $priority, $sequenceOrder, $startsAt, $endsAt,
    ];

    $pdo->beginTransaction();
    if (function_exists('authorization_lock_actor_permissions') && !authorization_lock_actor_permissions($pdo, $jpmUserId)) throw new RuntimeException('Unable to lock actor permissions.');
    if (function_exists('user_can') && !user_can($pdo, $jpmUserId, JPM_PERMISSION)) throw new RuntimeException('Campaign permission changed.');
    if ($htmlPolicy === 'trusted' && (!function_exists('user_can') || !user_can($pdo, $jpmUserId, JPM_TRUSTED_HTML_PERMISSION))) throw new RuntimeException('Trusted HTML permission changed.');
    $before = null;
    if ($id > 0) {
        $before = jpm_campaign($pdo, $id, true);
        if (!is_array($before)) throw new DomainException(jpm_t('Popup campaign not found.'));
        $stmt = $pdo->prepare(
            'UPDATE `' . JPM_CAMPAIGNS_TABLE . '` SET
             name=?,status=?,content_type=?,html_policy=?,html_content=?,desktop_media_id=?,tablet_media_id=?,mobile_media_id=?,image_alt=?,target_url=?,open_new_tab=?,target_mode=?,include_rules=?,exclude_rules=?,context_rules=?,frequency=?,delay_ms=?,max_width=?,show_close=?,close_on_overlay=?,priority=?,sequence_order=?,starts_at=?,ends_at=?,updated_by=? WHERE id=?'
        );
        $stmt->execute(array_merge($values, [$jpmUserId, $id]));
        $event = 'jpm.campaign.updated';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO `' . JPM_CAMPAIGNS_TABLE . '`
             (name,status,content_type,html_policy,html_content,desktop_media_id,tablet_media_id,mobile_media_id,image_alt,target_url,open_new_tab,target_mode,include_rules,exclude_rules,context_rules,frequency,delay_ms,max_width,show_close,close_on_overlay,priority,sequence_order,starts_at,ends_at,created_by,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute(array_merge($values, [$jpmUserId, $jpmUserId]));
        $id = (int)$pdo->lastInsertId();
        $event = 'jpm.campaign.created';
    }
    if (function_exists('authorization_audit') && !authorization_audit($pdo, $event, $jpmUserId, null, 'popup-manager', (string)$id, ['before_status' => $before['status'] ?? null, 'after_status' => $status])) throw new RuntimeException('Unable to write audit event.');
    $pdo->commit();
    $redirect('success', jpm_t('Popup campaign saved.'), '/edit&id=' . $id);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $error instanceof DomainException ? $error->getMessage() : jpm_t('The campaign could not be saved.');
    if (!$error instanceof DomainException) error_log('[popup-manager] save error: ' . $error->getMessage());
    $redirect('error', $message, $id > 0 ? '/edit&id=' . $id : '/edit');
}
