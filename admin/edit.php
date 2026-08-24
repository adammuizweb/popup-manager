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
    'id' => 0, 'name' => '', 'status' => 'draft', 'content_type' => 'image', 'html_content' => '',
    'desktop_media_id' => null, 'tablet_media_id' => null, 'mobile_media_id' => null, 'image_alt' => '',
    'target_url' => '', 'open_new_tab' => 0, 'target_mode' => 'all', 'include_rules' => '[]',
    'exclude_rules' => '[]', 'frequency' => 'session', 'delay_ms' => 500, 'max_width' => 720,
    'show_close' => 1, 'close_on_overlay' => 1, 'priority' => 0, 'starts_at' => null, 'ends_at' => null,
];
$media = jpm_campaign_media($pdo, $campaign);
$ruleText = static fn(mixed $value): string => implode("\n", jpm_rules_decode($value));
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

  <form method="post" action="<?=jpm_h($jpmBase . '/save')?>" class="jpm-form">
    <input type="hidden" name="csrf_token" value="<?=jpm_h(csrf_token())?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=(int)$campaign['id']?>">

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
            <textarea class="adam-input jpm-code" id="jpm-html-content" name="html_content" rows="14" placeholder="<div>...</div>"><?=jpm_h((string)$campaign['html_content'])?></textarea>
            <p class="jpm-hint"><?=jpm_h(jpm_t('Scripts, inline styles, forms, iframes, event handlers, and unsafe URLs are removed when saved.'))?></p>
          </div>
        </section>

        <section class="jpm-card">
          <div class="jpm-card-head"><div><span>02</span><h2><?=jpm_h(jpm_t('Route targeting'))?></h2></div><p><?=jpm_h(jpm_t('Exclude rules always override include rules.'))?></p></div>
          <label class="jpm-field"><span><?=jpm_h(jpm_t('Show campaign on'))?></span><select class="adam-input" name="target_mode" data-jpm-target-mode><option value="all" <?=(string)$campaign['target_mode']==='all'?'selected':''?>><?=jpm_h(jpm_t('All public pages'))?></option><option value="homepage" <?=(string)$campaign['target_mode']==='homepage'?'selected':''?>><?=jpm_h(jpm_t('Homepage only'))?></option><option value="paths" <?=(string)$campaign['target_mode']==='paths'?'selected':''?>><?=jpm_h(jpm_t('Selected paths'))?></option></select></label>
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
            <label class="jpm-field"><span><?=jpm_h(jpm_t('Priority'))?></span><input class="adam-input" type="number" name="priority" min="-1000" max="1000" value="<?=(int)$campaign['priority']?>"></label>
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
