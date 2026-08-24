<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$filters = [
    'search' => mb_substr(trim((string)($_GET['search'] ?? '')), 0, 100, 'UTF-8'),
    'status' => in_array((string)($_GET['status'] ?? ''), ['draft', 'active', 'paused'], true) ? (string)$_GET['status'] : '',
    'type' => in_array((string)($_GET['type'] ?? ''), ['image', 'html'], true) ? (string)$_GET['type'] : '',
    'target' => in_array((string)($_GET['target'] ?? ''), ['all', 'homepage', 'paths', 'contexts'], true) ? (string)$_GET['target'] : '',
];
$result = jpm_campaign_page($pdo, $filters, max(1, (int)($_GET['p'] ?? 1)), 20);
$campaigns = $result['rows'];
$stats = jpm_campaign_summary($pdo);
$statusLabels = ['draft' => jpm_t('Draft'), 'active' => jpm_t('Active'), 'paused' => jpm_t('Paused')];
$targetLabels = ['all' => jpm_t('All public pages'), 'homepage' => jpm_t('Homepage only'), 'paths' => jpm_t('Custom paths'), 'contexts' => jpm_t('Selected page types')];
$frequencyLabels = [
    'every_view' => jpm_t('Every page view'), 'session' => jpm_t('Once per session'),
    'path_session' => jpm_t('Once per path in a session'), 'visitor' => jpm_t('Once per visitor'), 'daily' => jpm_t('Once per day'),
];
$queryUrl = static function (int $page) use ($jpmBase, $filters): string {
    $query = array_filter($filters, static fn(string $value): bool => $value !== '');
    if ($page > 1) $query['p'] = $page;
    return $jpmBase . ($query === [] ? '' : '&' . http_build_query($query));
};
$impressions = (int)$stats['impressions'];
$clicks = (int)$stats['clicks'];
?>
<section class="jpm-admin" id="jpm-admin"
  data-delete-confirm="<?=jpm_h(jpm_t('Delete this popup campaign?'))?>"
  data-bulk-delete-confirm="<?=jpm_h(jpm_t('Delete all selected popup campaigns?'))?>"
  data-selected-template="<?=jpm_h(jpm_t('%d selected'))?>">
  <div class="jpm-head">
    <div>
      <div class="jpm-kicker"><?=jpm_h(jpm_t('Audience campaigns'))?></div>
      <h1><?=jpm_h(jpm_t('Popup Manager'))?></h1>
      <p><?=jpm_h(jpm_t('Build an ordered popup queue with dynamic content, targeting, and aggregate engagement statistics.'))?></p>
    </div>
    <a class="adam-button" href="<?=jpm_h($jpmBase . '/edit')?>"><?=jpm_h(jpm_t('Create campaign'))?></a>
  </div>

  <div class="jpm-stats jpm-stats--wide">
    <div><span><?=jpm_h(jpm_t('Campaigns'))?></span><strong><?=(int)$stats['total']?></strong></div>
    <div><span><?=jpm_h(jpm_t('Active'))?></span><strong><?=(int)$stats['active']?></strong></div>
    <div><span><?=jpm_h(jpm_t('Impressions'))?></span><strong><?=number_format($impressions)?></strong></div>
    <div><span><?=jpm_h(jpm_t('Closes'))?></span><strong><?=number_format((int)$stats['closes'])?></strong></div>
    <div><span><?=jpm_h(jpm_t('Clicks'))?></span><strong><?=number_format($clicks)?></strong></div>
    <div><span><?=jpm_h(jpm_t('Click rate'))?></span><strong><?=$impressions > 0 ? jpm_h(number_format(($clicks / $impressions) * 100, 1) . '%') : '0%'?></strong></div>
  </div>

  <div class="jpm-info">
    <strong><?=jpm_h(jpm_t('Queue behavior'))?></strong>
    <span><?=jpm_h(jpm_t('Eligible active campaigns open from the lowest queue number to the highest. Draft, paused, scheduled-out, targeted-out, and frequency-capped campaigns are skipped.'))?></span>
  </div>

  <form method="get" action="<?=jpm_h(rtrim((string)ADMIN_BASE_PATH, '/') . '/')?>" class="jpm-filters">
    <input type="hidden" name="page" value="admin/tools/popup-manager">
    <label><span><?=jpm_h(jpm_t('Search'))?></span><input class="adam-input" type="search" name="search" value="<?=jpm_h($filters['search'])?>" maxlength="100" placeholder="<?=jpm_h(jpm_t('Search campaign name...'))?>"></label>
    <label><span><?=jpm_h(jpm_t('Status'))?></span><select class="adam-input" name="status"><option value=""><?=jpm_h(jpm_t('All statuses'))?></option><?php foreach ($statusLabels as $value => $label): ?><option value="<?=jpm_h($value)?>" <?=$filters['status']===$value?'selected':''?>><?=jpm_h($label)?></option><?php endforeach; ?></select></label>
    <label><span><?=jpm_h(jpm_t('Content'))?></span><select class="adam-input" name="type"><option value=""><?=jpm_h(jpm_t('All content types'))?></option><option value="image" <?=$filters['type']==='image'?'selected':''?>><?=jpm_h(jpm_t('Responsive images'))?></option><option value="html" <?=$filters['type']==='html'?'selected':''?>><?=jpm_h(jpm_t('HTML content'))?></option></select></label>
    <label><span><?=jpm_h(jpm_t('Targeting'))?></span><select class="adam-input" name="target"><option value=""><?=jpm_h(jpm_t('All targeting modes'))?></option><?php foreach ($targetLabels as $value => $label): ?><option value="<?=jpm_h($value)?>" <?=$filters['target']===$value?'selected':''?>><?=jpm_h($label)?></option><?php endforeach; ?></select></label>
    <div class="jpm-filter-actions"><button class="adam-button" type="submit"><?=jpm_h(jpm_t('Filter'))?></button><?php if (implode('', $filters) !== ''): ?><a class="adam-button ghost" href="<?=jpm_h($jpmBase)?>"><?=jpm_h(jpm_t('Reset'))?></a><?php endif; ?></div>
  </form>

  <?php if ((int)$stats['total'] === 0): ?>
    <div class="jpm-empty"><h2><?=jpm_h(jpm_t('No popup campaigns yet'))?></h2><p><?=jpm_h(jpm_t('Create a responsive image campaign or compose safe HTML content.'))?></p><a class="adam-button" href="<?=jpm_h($jpmBase . '/edit')?>"><?=jpm_h(jpm_t('Create first campaign'))?></a></div>
  <?php elseif ($campaigns === []): ?>
    <div class="jpm-empty"><h2><?=jpm_h(jpm_t('No campaigns match these filters'))?></h2><p><?=jpm_h(jpm_t('Adjust the search or filters and try again.'))?></p><a class="adam-button ghost" href="<?=jpm_h($jpmBase)?>"><?=jpm_h(jpm_t('Reset filters'))?></a></div>
  <?php else: ?>
    <form id="jpm-bulk-form" method="post" action="<?=jpm_h($jpmBase . '/bulk')?>" class="jpm-bulk">
      <input type="hidden" name="csrf_token" value="<?=jpm_h(csrf_token())?>">
      <input type="hidden" name="action" value="bulk">
      <input type="hidden" name="return_search" value="<?=jpm_h($filters['search'])?>">
      <input type="hidden" name="return_status" value="<?=jpm_h($filters['status'])?>">
      <input type="hidden" name="return_type" value="<?=jpm_h($filters['type'])?>">
      <input type="hidden" name="return_target" value="<?=jpm_h($filters['target'])?>">
      <input type="hidden" name="return_page" value="<?=(int)$result['page']?>">
      <strong data-jpm-selected-count><?=jpm_h(jpm_t('0 selected'))?></strong>
      <select class="adam-input" name="bulk_action" required><option value=""><?=jpm_h(jpm_t('Bulk action'))?></option><option value="activate"><?=jpm_h(jpm_t('Activate'))?></option><option value="pause"><?=jpm_h(jpm_t('Pause'))?></option><option value="draft"><?=jpm_h(jpm_t('Move to draft'))?></option><option value="delete"><?=jpm_h(jpm_t('Delete'))?></option></select>
      <button class="adam-button" type="submit" data-jpm-bulk-apply disabled><?=jpm_h(jpm_t('Apply'))?></button>
      <details class="jpm-columns" data-jpm-columns>
        <summary><?=jpm_h(jpm_t('Columns'))?></summary>
        <div class="jpm-columns__panel" aria-label="<?=jpm_h(jpm_t('Choose visible columns'))?>">
          <label><input type="checkbox" value="order" data-jpm-column-toggle checked> <?=jpm_h(jpm_t('Order'))?></label>
          <label><input type="checkbox" value="targeting" data-jpm-column-toggle checked> <?=jpm_h(jpm_t('Targeting'))?></label>
          <label><input type="checkbox" value="engagement" data-jpm-column-toggle checked> <?=jpm_h(jpm_t('Engagement'))?></label>
          <label><input type="checkbox" value="status" data-jpm-column-toggle checked> <?=jpm_h(jpm_t('Status'))?></label>
        </div>
      </details>
    </form>

    <div class="jpm-table-wrap">
      <table class="jpm-table">
        <thead><tr><th><input type="checkbox" data-jpm-select-all aria-label="<?=jpm_h(jpm_t('Select all campaigns on this page'))?>"></th><th data-jpm-column="order"><?=jpm_h(jpm_t('Order'))?></th><th><?=jpm_h(jpm_t('Campaign'))?></th><th data-jpm-column="targeting"><?=jpm_h(jpm_t('Targeting'))?></th><th data-jpm-column="engagement"><?=jpm_h(jpm_t('Engagement'))?></th><th data-jpm-column="status"><?=jpm_h(jpm_t('Status'))?></th><th><?=jpm_h(jpm_t('Actions'))?></th></tr></thead>
        <tbody>
        <?php foreach ($campaigns as $campaign):
          $id = (int)$campaign['id']; $status = (string)$campaign['status']; $nextStatus = $status === 'active' ? 'paused' : 'active';
          $rowImpressions = (int)$campaign['impression_count']; $rowClicks = (int)$campaign['click_count'];
        ?>
          <tr data-jpm-row>
            <td><input type="checkbox" name="campaign_ids[]" value="<?=$id?>" form="jpm-bulk-form" data-jpm-select-row aria-label="<?=jpm_h(jpm_t('Select %s', (string)$campaign['name']))?>"></td>
            <td data-jpm-column="order"><strong class="jpm-order"><?=(int)$campaign['sequence_order']?></strong><div class="jpm-order-actions"><form method="post" action="<?=jpm_h($jpmBase . '/save')?>"><input type="hidden" name="csrf_token" value="<?=jpm_h(csrf_token())?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="direction" value="up"><button type="submit" aria-label="<?=jpm_h(jpm_t('Move campaign up'))?>">&uarr;</button></form><form method="post" action="<?=jpm_h($jpmBase . '/save')?>"><input type="hidden" name="csrf_token" value="<?=jpm_h(csrf_token())?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="direction" value="down"><button type="submit" aria-label="<?=jpm_h(jpm_t('Move campaign down'))?>">&darr;</button></form></div></td>
            <td><a class="jpm-title" href="<?=jpm_h($jpmBase . '/edit&id=' . $id)?>"><?=jpm_h((string)$campaign['name'])?></a><div class="jpm-meta"><span><?=jpm_h((string)$campaign['content_type'] === 'image' ? jpm_t('Responsive images') : jpm_t('HTML content'))?></span><span><?=jpm_h($frequencyLabels[(string)$campaign['frequency']] ?? (string)$campaign['frequency'])?></span><span><?=jpm_h(jpm_t('%d ms delay', (int)$campaign['delay_ms']))?></span></div></td>
            <td data-jpm-column="targeting"><strong><?=jpm_h($targetLabels[(string)$campaign['target_mode']] ?? (string)$campaign['target_mode'])?></strong><?php if ((string)$campaign['target_mode'] === 'contexts'): ?><small><?=jpm_h(jpm_t('%d page types', count(jpm_context_rules_decode($campaign['context_rules'] ?? null))))?></small><?php endif; ?><?php if (!empty($campaign['starts_at']) || !empty($campaign['ends_at'])): ?><small><?=jpm_h(jpm_t('Scheduled'))?></small><?php endif; ?></td>
            <td data-jpm-column="engagement"><div class="jpm-metrics"><span><b><?=number_format($rowImpressions)?></b> <?=jpm_h(jpm_t('views'))?></span><span><b><?=number_format((int)$campaign['close_count'])?></b> <?=jpm_h(jpm_t('closes'))?></span><span><b><?=number_format($rowClicks)?></b> <?=jpm_h(jpm_t('clicks'))?></span><small><?=$rowImpressions > 0 ? jpm_h(number_format(($rowClicks / $rowImpressions) * 100, 1) . '% CTR') : '0% CTR'?></small></div></td>
            <td data-jpm-column="status"><span class="jpm-status is-<?=jpm_h($status)?>"><?=jpm_h($statusLabels[$status] ?? $status)?></span></td>
            <td>
              <div class="jpm-action-menu" data-jpm-action-menu>
                <button class="jpm-action-trigger" id="jpm-action-trigger-<?=$id?>" type="button" data-jpm-action-trigger aria-expanded="false" aria-controls="jpm-action-panel-<?=$id?>" aria-label="<?=jpm_h(jpm_t('Actions for %s', (string)$campaign['name']))?>">&#8230;</button>
                <div class="jpm-action-panel" id="jpm-action-panel-<?=$id?>" data-jpm-action-panel role="group" aria-labelledby="jpm-action-trigger-<?=$id?>" hidden>
                  <a href="<?=jpm_h($jpmBase . '/edit&id=' . $id)?>"><?=jpm_h(jpm_t('Edit'))?></a>
                  <form method="post" action="<?=jpm_h($jpmBase . '/save')?>"><input type="hidden" name="csrf_token" value="<?=jpm_h(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="status" value="<?=jpm_h($nextStatus)?>"><button type="submit"><?=jpm_h($status === 'active' ? jpm_t('Pause') : jpm_t('Activate'))?></button></form>
                  <form method="post" action="<?=jpm_h($jpmBase . '/delete')?>" data-jpm-delete-form><input type="hidden" name="csrf_token" value="<?=jpm_h(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$id?>"><button class="jpm-danger" type="submit"><?=jpm_h(jpm_t('Delete'))?></button></form>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ((int)$result['pages'] > 1): ?>
      <nav class="jpm-pagination" aria-label="<?=jpm_h(jpm_t('Campaign pages'))?>">
        <?php if ((int)$result['page'] > 1): ?><a class="adam-button ghost" href="<?=jpm_h($queryUrl((int)$result['page'] - 1))?>"><?=jpm_h(jpm_t('Previous'))?></a><?php endif; ?>
        <?php for ($page = max(1, (int)$result['page'] - 2); $page <= min((int)$result['pages'], (int)$result['page'] + 2); $page++): ?><a class="jpm-page <?=$page===(int)$result['page']?'is-current':''?>" href="<?=jpm_h($queryUrl($page))?>" <?=$page===(int)$result['page']?'aria-current="page"':''?>><?=$page?></a><?php endfor; ?>
        <?php if ((int)$result['page'] < (int)$result['pages']): ?><a class="adam-button ghost" href="<?=jpm_h($queryUrl((int)$result['page'] + 1))?>"><?=jpm_h(jpm_t('Next'))?></a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
