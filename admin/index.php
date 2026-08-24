<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$campaigns = jpm_campaigns($pdo);
$stats = ['total' => count($campaigns), 'active' => 0, 'scheduled' => 0, 'draft' => 0];
$now = new DateTimeImmutable();
foreach ($campaigns as $campaign) {
    $status = (string)$campaign['status'];
    if ($status === 'active') $stats['active']++;
    if ($status === 'draft') $stats['draft']++;
    if ($status === 'active' && ((!empty($campaign['starts_at']) && new DateTimeImmutable((string)$campaign['starts_at']) > $now)
        || (!empty($campaign['ends_at']) && new DateTimeImmutable((string)$campaign['ends_at']) < $now))) $stats['scheduled']++;
}
$statusLabels = ['draft' => jpm_t('Draft'), 'active' => jpm_t('Active'), 'paused' => jpm_t('Paused')];
$targetLabels = ['all' => jpm_t('All public pages'), 'homepage' => jpm_t('Homepage only'), 'paths' => jpm_t('Selected paths')];
$frequencyLabels = [
    'every_view' => jpm_t('Every page view'),
    'session' => jpm_t('Once per session'),
    'path_session' => jpm_t('Once per path in a session'),
    'visitor' => jpm_t('Once per visitor'),
    'daily' => jpm_t('Once per day'),
];
?>
<section class="jpm-admin" id="jpm-admin" data-delete-confirm="<?=jpm_h(jpm_t('Delete this popup campaign?'))?>">
  <div class="jpm-head">
    <div>
      <div class="jpm-kicker"><?=jpm_h(jpm_t('Audience campaigns'))?></div>
      <h1><?=jpm_h(jpm_t('Popup Manager'))?></h1>
      <p><?=jpm_h(jpm_t('Show one prioritized popup campaign without hardcoding theme templates.'))?></p>
    </div>
    <a class="adam-button" href="<?=jpm_h($jpmBase . '/edit')?>"><?=jpm_h(jpm_t('Create campaign'))?></a>
  </div>

  <div class="jpm-stats">
    <div><span><?=jpm_h(jpm_t('Campaigns'))?></span><strong><?=$stats['total']?></strong></div>
    <div><span><?=jpm_h(jpm_t('Active'))?></span><strong><?=$stats['active']?></strong></div>
    <div><span><?=jpm_h(jpm_t('Outside schedule'))?></span><strong><?=$stats['scheduled']?></strong></div>
    <div><span><?=jpm_h(jpm_t('Drafts'))?></span><strong><?=$stats['draft']?></strong></div>
  </div>

  <div class="jpm-info">
    <strong><?=jpm_h(jpm_t('Runtime rule'))?></strong>
    <span><?=jpm_h(jpm_t('When multiple campaigns match, only the active campaign with the highest priority is shown.'))?></span>
  </div>

  <?php if ($campaigns === []): ?>
    <div class="jpm-empty">
      <h2><?=jpm_h(jpm_t('No popup campaigns yet'))?></h2>
      <p><?=jpm_h(jpm_t('Create a responsive image campaign or compose safe HTML content.'))?></p>
      <a class="adam-button" href="<?=jpm_h($jpmBase . '/edit')?>"><?=jpm_h(jpm_t('Create first campaign'))?></a>
    </div>
  <?php else: ?>
    <div class="jpm-table-wrap">
      <table class="jpm-table">
        <thead><tr><th><?=jpm_h(jpm_t('Campaign'))?></th><th><?=jpm_h(jpm_t('Targeting'))?></th><th><?=jpm_h(jpm_t('Frequency'))?></th><th><?=jpm_h(jpm_t('Status'))?></th><th><?=jpm_h(jpm_t('Actions'))?></th></tr></thead>
        <tbody>
        <?php foreach ($campaigns as $campaign):
          $id = (int)$campaign['id'];
          $status = (string)$campaign['status'];
          $nextStatus = $status === 'active' ? 'paused' : 'active';
        ?>
          <tr>
            <td>
              <a class="jpm-title" href="<?=jpm_h($jpmBase . '/edit&id=' . $id)?>"><?=jpm_h((string)$campaign['name'])?></a>
              <div class="jpm-meta">
                <span><?=jpm_h(ucfirst((string)$campaign['content_type']))?></span>
                <span><?=jpm_h(jpm_t('Priority %d', (int)$campaign['priority']))?></span>
                <span><?=jpm_h(jpm_t('%d ms delay', (int)$campaign['delay_ms']))?></span>
              </div>
            </td>
            <td><strong><?=jpm_h($targetLabels[(string)$campaign['target_mode']] ?? (string)$campaign['target_mode'])?></strong><?php if (!empty($campaign['starts_at']) || !empty($campaign['ends_at'])): ?><small><?=jpm_h(jpm_t('Scheduled'))?></small><?php endif; ?></td>
            <td><?=jpm_h($frequencyLabels[(string)$campaign['frequency']] ?? (string)$campaign['frequency'])?></td>
            <td><span class="jpm-status is-<?=jpm_h($status)?>"><?=jpm_h($statusLabels[$status] ?? $status)?></span></td>
            <td>
              <div class="jpm-actions">
                <a class="adam-button ghost" href="<?=jpm_h($jpmBase . '/edit&id=' . $id)?>"><?=jpm_h(jpm_t('Edit'))?></a>
                <form method="post" action="<?=jpm_h($jpmBase . '/save')?>">
                  <input type="hidden" name="csrf_token" value="<?=jpm_h(csrf_token())?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?=$id?>">
                  <input type="hidden" name="status" value="<?=jpm_h($nextStatus)?>">
                  <button class="adam-button ghost" type="submit"><?=jpm_h($status === 'active' ? jpm_t('Pause') : jpm_t('Activate'))?></button>
                </form>
                <form method="post" action="<?=jpm_h($jpmBase . '/delete')?>" data-jpm-delete-form>
                  <input type="hidden" name="csrf_token" value="<?=jpm_h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=$id?>">
                  <button class="adam-button ghost jpm-danger" type="submit"><?=jpm_h(jpm_t('Delete'))?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
