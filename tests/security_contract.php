<?php
declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$coreRoot = rtrim((string)(getenv('JPM_CORE_ROOT') ?: dirname($pluginRoot, 2) . '/jyavani.lan'), DIRECTORY_SEPARATOR);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

function jpm_t(string $source, mixed ...$args): string
{
    return $args === [] ? $source : sprintf($source, ...$args);
}

require_once $pluginRoot . '/includes/targeting.php';

$check(jpm_normalize_path('/news//release/?source=test') === '/news/release', 'request paths normalize separators and ignore query strings');
$check(jpm_normalize_path('/safe/%2e%2e/private') === null, 'encoded traversal paths fail closed');
$check(jpm_normalize_rule('/news/*') === '/news/*' && jpm_normalize_rule('news/*') === null, 'rules require a root-relative exact or subtree path');
$check(jpm_rule_matches('/news', '/news/*') && jpm_rule_matches('/news/item', '/news/*'), 'subtree rules include their root and descendants');
$check(!jpm_rule_matches('/newsletter', '/news/*'), 'subtree rules respect path segment boundaries');

$blankLines = implode("\n", array_fill(0, 60, '')) . "\n/news/*";
$check(jpm_rules_from_text($blankLines) === ['/news/*'], 'blank lines do not consume the bounded rule limit');
try {
    jpm_rules_from_text(implode("\n", array_map(static fn(int $id): string => '/path-' . $id, range(1, 51))));
    $bounded = false;
} catch (DomainException) {
    $bounded = true;
}
$check($bounded, 'campaign rule count is bounded');

$campaign = ['target_mode' => 'paths', 'include_rules' => '["/news/*"]', 'exclude_rules' => '["/news/private/*"]'];
$check(jpm_target_matches($campaign, '/news/public', false), 'included path is eligible');
$check(!jpm_target_matches($campaign, '/news/private/item', false), 'exclude rules override includes');
$check(jpm_target_matches(['target_mode' => 'homepage'], '/', true), 'homepage targeting uses the resolved layout context');

foreach (['/static/file.js', '/private/media/1', '/pondasi', '/api/items', '/.well-known/test', '/sw.js', '/sitemap.xml'] as $path) {
    $check(jpm_is_sensitive_path(null, $path), 'sensitive Core path is blocked: ' . $path);
}
$check(!jpm_is_sensitive_path(null, '/articles/example'), 'ordinary public paths remain eligible');

$check(jpm_normalize_target_url('/admissions/?source=popup') === '/admissions/?source=popup', 'root-relative destinations are accepted');
$check(jpm_normalize_target_url('https://example.com/admissions') === 'https://example.com/admissions', 'HTTP(S) destinations are accepted');
$check(jpm_normalize_target_url('//example.com') === null, 'protocol-relative destinations are rejected');
$check(jpm_normalize_target_url('javascript:alert(1)') === null, 'script destinations are rejected');
$check(jpm_normalize_target_url('https://user:pass@example.com') === null, 'credential-bearing destinations are rejected');

$check(jpm_sanitize_html('<p onclick="alert(1)">Unsafe</p>') === '', 'HTML fails closed when the Core sanitizer is unavailable');
if (!is_file($coreRoot . '/cfg/helpers/cms_content.php')) {
    fwrite(STDERR, "Canonical Core sanitizer not found at {$coreRoot}. Set JPM_CORE_ROOT.\n");
    exit(1);
}
require_once $coreRoot . '/cfg/helpers/cms_content.php';
$sanitized = jpm_sanitize_html('<div class="announcement" style="color:red" onclick="alert(1)"><script>alert(1)</script><a href="javascript:alert(1)" target="_blank">Open</a></div>');
$check(!str_contains($sanitized, '<script') && !str_contains($sanitized, 'onclick=') && !str_contains($sanitized, 'style='), 'restricted HTML removes active content and inline styling');
$check(!str_contains($sanitized, 'javascript:') && str_contains($sanitized, 'class="announcement"'), 'restricted HTML removes unsafe URLs and preserves supported classes');
$safeBlank = jpm_sanitize_html('<a href="https://example.com" target="_blank">Open</a>');
$check(str_contains($safeBlank, 'rel="noopener noreferrer"'), 'new-tab HTML links receive opener isolation');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " security assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
