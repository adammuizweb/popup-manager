<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$id = max(0, (int)($_GET['id'] ?? 0));
$campaign = $id > 0 ? jpm_campaign($pdo, $id) : null;
if ($id > 0 && !is_array($campaign)) {
    adiwira_render_404();
    return;
}
$campaign ??= [
    'id' => 0, 'name' => '', 'status' => 'draft', 'content_type' => 'image', 'html_policy' => 'restricted', 'html_content' => '',
    'desktop_media_id' => null, 'tablet_media_id' => null, 'mobile_media_id' => null, 'image_alt' => '',
    'target_url' => '', 'open_new_tab' => 0, 'target_mode' => 'all', 'include_rules' => '[]',
    'exclude_rules' => '[]', 'context_rules' => '[]', 'frequency' => 'session', 'delay_ms' => 500, 'max_width' => 720,
    'show_close' => 1, 'close_on_overlay' => 1, 'priority' => 0, 'sequence_order' => jpm_next_sequence_order($pdo),
    'impression_count' => 0, 'close_count' => 0, 'click_count' => 0, 'starts_at' => null, 'ends_at' => null,
];
$canTrustedHtml = function_exists('user_can') && user_can($pdo, $jpmUserId, JPM_TRUSTED_HTML_PERMISSION);
if ($id > 0 && (string)$campaign['content_type'] === 'html' && (string)($campaign['html_policy'] ?? 'restricted') === 'trusted' && !$canTrustedHtml) {
    adiwira_render_404();
    return;
}
$media = jpm_campaign_media($pdo, $campaign);
$ruleText = static fn(mixed $value): string => implode("\n", jpm_rules_decode($value));
$contextRules = jpm_context_rules_decode($campaign['context_rules'] ?? null);
$contextOptions = jpm_context_options();
$imageGuides = [
    'desktop' => jpm_t('Recommended 1440 x 900 px | viewport above 1024 px'),
    'tablet' => jpm_t('Recommended 1024 x 640 px | viewport 641-1024 px'),
    'mobile' => jpm_t('Recommended 640 x 800 px | viewport up to 640 px'),
];
$dateInput = static fn(mixed $value): string => empty($value) ? '' : (new DateTimeImmutable((string)$value))->format('Y-m-d\TH:i');
$starter = '<div class="announcement">' . "\n"
    . '  <h2>' . jpm_t('Admissions are now open') . '</h2>' . "\n"
    . '  <p>' . jpm_t('Add useful campaign information here.') . '</p>' . "\n"
    . '  <p><a href="/">' . jpm_t('Learn more') . '</a></p>' . "\n"
    . '</div>';
?>
<section class="jpm-admin jpm-editor" id="jpm-admin" data-jpm-editor data-starter="<?=jpm_h($starter)?>">
  <div class="jpm-head">
    <div>
      <a class="jpm-back" href="<?=jpm_h($jpmBase)?>">&larr; <?=jpm_h(jpm_t('All campaigns'))?></a>
      <div class="jpm-kicker"><?=jpm_h($id > 0 ? jpm_t('Edit campaign') : jpm_t('New campaign'))?></div>
      <h1><?=jpm_h($id > 0 ? (string)$campaign['name'] : jpm_t('Create popup campaign'))?></h1>
      <p><?=jpm_h(jpm_t('Content, targeting, timing, and display frequency remain independent from the active theme.'))?></p>
    </div>
  </div>

  <?php if ($id > 0):
    $impressions = (int)$campaign['impression_count'];
    $closes = (int)$campaign['close_count'];
    $clicks = (int)$campaign['click_count'];
  ?>
    <div class="jpm-stats jpm-stats--campaign">
      <div><span><?=jpm_h(jpm_t('Impressions'))?></span><strong><?=number_format($impressions)?></strong></div>
      <div><span><?=jpm_h(jpm_t('Closes'))?></span><strong><?=number_format($closes)?></strong></div>
      <div><span><?=jpm_h(jpm_t('Clicks'))?></span><strong><?=number_format($clicks)?></strong></div>
      <div><span><?=jpm_h(jpm_t('Click rate'))?></span><strong><?=$impressions > 0 ? jpm_h(number_format(($clicks / $impressions) * 100, 1) . '%') : '0%'?></strong></div>
    </div>
  <?php endif; ?>

  <form method="post" action="<?=jpm_h($jpmBase . '/save')?>" class="jpm-form">
    <input type="hidden" name="csrf_token" value="<?=jpm_h(csrf_token())?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=(int)$campaign['id']?>">
    <input type="hidden" name="priority" value="<?=(int)$campaign['priority']?>">

    <div class="jpm-editor-grid">
      <main class="jpm-editor-main">
        <section class="jpm-card">
          <div class="jpm-card-head"><div><span>01</span><h2><?=jpm_h(jpm_t('Campaign content'))?></h2></div><p><?=jpm_h(jpm_t('Choose responsive images or sanitized HTML.'))?></p></div>
          <label class="jpm-field"><span><?=jpm_h(jpm_t('Internal campaign name'))?></span><input class="adam-input" name="name" maxlength="190" required value="<?=jpm_h((string)$campaign['name'])?>" placeholder="<?=jpm_h(jpm_t('Example: 2026 admissions announcement'))?>"></label>
          <div class="jpm-mode-switch" role="group" aria-label="<?=jpm_h(jpm_t('Content type'))?>">
            <label><input type="radio" name="content_type" value="image" <?=(string)$campaign['content_type']==='image'?'checked':''?>><span><?=jpm_h(jpm_t('Responsive images'))?><small><?=jpm_h(jpm_t('Desktop with optional tablet and mobile variants'))?></small></span></label>
            <label><input type="radio" name="content_type" value="html" <?=(string)$campaign['content_type']==='html'?'checked':''?>><span><?=jpm_h(jpm_t('HTML content'))?><small><?=jpm_h(jpm_t('Flexible markup sanitized by Jyavani Core'))?></small></span></label>
          </div>

          <div data-jpm-image-fields>
            <div class="jpm-media-grid">
              <?php foreach (['desktop' => jpm_t('Desktop image'), 'tablet' => jpm_t('Tablet image'), 'mobile' => jpm_t('Mobile image')] as $device => $label):
                $selected = $media[$device];
              ?>
                <div class="jpm-media" data-jpm-media>
                  <div class="jpm-media-label"><strong><?=jpm_h($label)?></strong><small><?=$device==='desktop'?jpm_h(jpm_t('Required')):jpm_h(jpm_t('Optional fallback'))?></small></div>
                  <div class="jpm-media-preview"><img data-jpm-media-preview src="<?=jpm_h((string)($selected['url'] ?? ''))?>" alt="" <?=is_array($selected)?'':'hidden'?>><span data-jpm-media-empty <?=is_array($selected)?'hidden':''?>><?=jpm_h(jpm_t('No image selected'))?></span></div>
                  <p class="jpm-media-guide"><?=jpm_h($imageGuides[$device])?><?php if (is_array($selected) && (int)($selected['width'] ?? 0) > 0): ?><br><strong><?=jpm_h(jpm_t('Selected: %d x %d px', (int)$selected['width'], (int)$selected['height']))?></strong><?php endif; ?></p>
                  <input type="hidden" name="<?=$device?>_media_id" data-jpm-media-id value="<?=(int)($campaign[$device . '_media_id'] ?? 0)?>">
                  <div class="jpm-media-actions"><button class="adam-button" type="button" data-jpm-choose-media><?=jpm_h(jpm_t('Choose image'))?></button><button class="adam-button ghost" type="button" data-jpm-clear-media><?=jpm_h(jpm_t('Clear'))?></button></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="jpm-two-col">
              <label class="jpm-field"><span><?=jpm_h(jpm_t('Image alternative text'))?></span><input class="adam-input" name="image_alt" maxlength="255" value="<?=jpm_h((string)$campaign['image_alt'])?>"></label>
              <label class="jpm-field"><span><?=jpm_h(jpm_t('Click destination'))?></span><input class="adam-input" type="text" name="target_url" value="<?=jpm_h((string)$campaign['target_url'])?>" placeholder="https://example.com/ or /admissions/"></label>
            </div>
            <label class="jpm-check"><input type="checkbox" name="open_new_tab" value="1" <?=(int)$campaign['open_new_tab']===1?'checked':''?>> <span><?=jpm_h(jpm_t('Open image destination in a new tab'))?></span></label>
          </div>

          <div data-jpm-html-fields>
            <div class="jpm-html-head"><label for="jpm-html-content"><?=jpm_h(jpm_t('HTML source'))?></label><button class="adam-button ghost" type="button" data-jpm-starter><?=jpm_h(jpm_t('Insert starter'))?></button></div>
            <div class="jpm-html-editor" data-jpm-html-editor
                 data-rich-placeholder="<?=jpm_h(jpm_t('Compose popup content...'))?>"
                 data-complex-title="<?=jpm_h(jpm_t('Complex HTML detected'))?>"
                 data-complex-message="<?=jpm_h(jpm_t('Rich Text may remove or normalize scripts, styles, embeds, forms, tables, and event handlers. Stay in HTML Code to preserve the exact markup.'))?>"
                 data-complex-confirm="<?=jpm_h(jpm_t('Switch to Rich Text'))?>"
                 data-complex-cancel="<?=jpm_h(jpm_t('Stay in HTML Code'))?>">
              <div class="jpm-editor-modes" role="group" aria-label="<?=jpm_h(jpm_t('HTML editor'))?>">
                <label><input type="radio" name="html_editor_mode" value="rich"> <span><?=jpm_h(jpm_t('Rich Text'))?></span></label>
                <label><input type="radio" name="html_editor_mode" value="code" checked> <span><?=jpm_h(jpm_t('HTML Code'))?></span></label>
              </div>
              <div data-jpm-code-area><textarea class="adam-input jpm-code" id="jpm-html-content" name="html_content" rows="14" placeholder="<div>...</div>"><?=jpm_h((string)$campaign['html_content'])?></textarea></div>
              <div class="jpm-rich-area adam-quill adam-quill--auto" data-jpm-rich-area hidden><div data-jpm-rich-editor></div></div>
            </div>
            <?php if ($canTrustedHtml): ?>
              <fieldset class="jpm-html-policy">
                <legend><?=jpm_h(jpm_t('HTML policy'))?></legend>
                <label><input type="radio" name="html_policy" value="restricted" <?=(string)($campaign['html_policy'] ?? 'restricted')==='restricted'?'checked':''?>> <span><strong><?=jpm_h(jpm_t('Restricted HTML'))?></strong><small><?=jpm_h(jpm_t('Safe content tags and links; iframe and style are removed.'))?></small></span></label>
                <label><input type="radio" name="html_policy" value="trusted" <?=(string)($campaign['html_policy'] ?? '')==='trusted'?'checked':''?>> <span><strong><?=jpm_h(jpm_t('Trusted Embed HTML'))?></strong><small><?=jpm_h(jpm_t('Site Owner only. Allows iframe, style, video, and audio; scripts and event handlers remain blocked.'))?></small></span></label>
              </fieldset>
            <?php else: ?>
              <input type="hidden" name="html_policy" value="restricted">
            <?php endif; ?>
            <p class="jpm-hint"><?=jpm_h(jpm_t('CodeMirror preserves exact markup. Rich Text may normalize complex HTML; use HTML Code for embeds and custom styling.'))?></p>
          </div>
        </section>

        <section class="jpm-card">
          <div class="jpm-card-head"><div><span>02</span><h2><?=jpm_h(jpm_t('Route targeting'))?></h2></div><p><?=jpm_h(jpm_t('Exclude rules always override include rules.'))?></p></div>
          <label class="jpm-field"><span><?=jpm_h(jpm_t('Show campaign on'))?></span><select class="adam-input" name="target_mode" data-jpm-target-mode><option value="all" <?=(string)$campaign['target_mode']==='all'?'selected':''?>><?=jpm_h(jpm_t('All public pages'))?></option><option value="homepage" <?=(string)$campaign['target_mode']==='homepage'?'selected':''?>><?=jpm_h(jpm_t('Homepage only'))?></option><option value="contexts" <?=(string)$campaign['target_mode']==='contexts'?'selected':''?>><?=jpm_h(jpm_t('Selected page types'))?></option><option value="paths" <?=(string)$campaign['target_mode']==='paths'?'selected':''?>><?=jpm_h(jpm_t('Custom paths'))?></option></select></label>
          <div class="jpm-contexts" data-jpm-context-fields>
            <?php foreach ($contextOptions as $value => $label): ?>
              <label><input type="checkbox" name="context_rules[]" value="<?=jpm_h($value)?>" <?=in_array($value, $contextRules, true)?'checked':''?>> <span><?=jpm_h($label)?></span></label>
            <?php endforeach; ?>
            <p><?=jpm_h(jpm_t('Page types are detected from Jyavani Core semantic hooks. Use Custom paths for an exact route or subtree.'))?></p>
          </div>
          <div class="jpm-two-col" data-jpm-path-fields>
            <label class="jpm-field"><span><?=jpm_h(jpm_t('Included paths'))?></span><textarea class="adam-input jpm-rules" name="include_rules" rows="7" placeholder="/admissions&#10;/news/*"><?=jpm_h($ruleText($campaign['include_rules']))?></textarea><small><?=jpm_h(jpm_t('One exact path or subtree prefix per line. Use /* after a path for its descendants.'))?></small></label>
            <label class="jpm-field"><span><?=jpm_h(jpm_t('Excluded paths'))?></span><textarea class="adam-input jpm-rules" name="exclude_rules" rows="7" placeholder="/privacy&#10;/members/*"><?=jpm_h($ruleText($campaign['exclude_rules']))?></textarea><small><?=jpm_h(jpm_t('Sensitive Core routes are excluded automatically.'))?></small></label>
          </div>
        </section>
      </main>

      <aside class="jpm-editor-side">
        <section class="jpm-card jpm-sticky">
          <div class="jpm-card-head"><div><span>03</span><h2><?=jpm_h(jpm_t('Delivery'))?></h2></div></div>
          <label class="jpm-field"><span><?=jpm_h(jpm_t('Status'))?></span><select class="adam-input" name="status"><option value="draft" <?=(string)$campaign['status']==='draft'?'selected':''?>><?=jpm_h(jpm_t('Draft'))?></option><option value="active" <?=(string)$campaign['status']==='active'?'selected':''?>><?=jpm_h(jpm_t('Active'))?></option><option value="paused" <?=(string)$campaign['status']==='paused'?'selected':''?>><?=jpm_h(jpm_t('Paused'))?></option></select></label>
          <label class="jpm-field"><span><?=jpm_h(jpm_t('Display frequency'))?></span><select class="adam-input" name="frequency"><option value="every_view" <?=(string)$campaign['frequency']==='every_view'?'selected':''?>><?=jpm_h(jpm_t('Every page view'))?></option><option value="session" <?=(string)$campaign['frequency']==='session'?'selected':''?>><?=jpm_h(jpm_t('Once per session'))?></option><option value="path_session" <?=(string)$campaign['frequency']==='path_session'?'selected':''?>><?=jpm_h(jpm_t('Once per path in a session'))?></option><option value="visitor" <?=(string)$campaign['frequency']==='visitor'?'selected':''?>><?=jpm_h(jpm_t('Once per visitor'))?></option><option value="daily" <?=(string)$campaign['frequency']==='daily'?'selected':''?>><?=jpm_h(jpm_t('Once per day'))?></option></select></label>
          <div class="jpm-two-col jpm-two-col--compact">
            <label class="jpm-field"><span><?=jpm_h(jpm_t('Delay (seconds)'))?></span><input class="adam-input" type="number" name="delay_seconds" min="0" max="60" step="0.1" value="<?=jpm_h(number_format((int)$campaign['delay_ms']/1000, 1, '.', ''))?>"></label>
            <label class="jpm-field"><span><?=jpm_h(jpm_t('Queue order'))?></span><input class="adam-input" type="number" name="sequence_order" min="1" max="100000" value="<?=(int)$campaign['sequence_order']?>"><small><?=jpm_h(jpm_t('Lower numbers appear first. Paused, draft, or ineligible campaigns are skipped.'))?></small></label>
          </div>
          <label class="jpm-field"><span><?=jpm_h(jpm_t('Maximum width (px)'))?></span><input class="adam-input" type="number" name="max_width" min="320" max="1200" value="<?=(int)$campaign['max_width']?>"></label>
          <label class="jpm-field"><span><?=jpm_h(jpm_t('Starts at'))?></span><input class="adam-input" type="datetime-local" name="starts_at" value="<?=jpm_h($dateInput($campaign['starts_at']))?>"></label>
          <label class="jpm-field"><span><?=jpm_h(jpm_t('Ends at'))?></span><input class="adam-input" type="datetime-local" name="ends_at" value="<?=jpm_h($dateInput($campaign['ends_at']))?>"></label>
          <label class="jpm-check"><input type="checkbox" name="show_close" value="1" <?=(int)$campaign['show_close']===1?'checked':''?>> <span><?=jpm_h(jpm_t('Show close button'))?></span></label>
          <label class="jpm-check"><input type="checkbox" name="close_on_overlay" value="1" <?=(int)$campaign['close_on_overlay']===1?'checked':''?>> <span><?=jpm_h(jpm_t('Close when overlay is clicked'))?></span></label>
          <div class="jpm-save"><button class="adam-button" type="submit"><?=jpm_h(jpm_t('Save campaign'))?></button><a class="adam-button ghost" href="<?=jpm_h($jpmBase)?>"><?=jpm_h(jpm_t('Cancel'))?></a></div>
        </section>
      </aside>
    </div>
  </form>
</section>
