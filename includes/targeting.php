<?php
declare(strict_types=1);

function jpm_normalize_path(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return '/';
    $path = parse_url($raw, PHP_URL_PATH);
    if (!is_string($path)) return null;
    if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path)) return null;
    $decoded = rawurldecode($path);
    if ($decoded === '' || str_contains($decoded, "\0") || preg_match('/[\x00-\x1F\x7F]/', $decoded)) return null;
    $decoded = '/' . ltrim(preg_replace('#/+#', '/', str_replace('\\', '/', $decoded)) ?? '', '/');
    $segments = explode('/', trim($decoded, '/'));
    if (array_intersect($segments, ['.', '..'])) return null;
    if ($decoded !== '/') $decoded = rtrim($decoded, '/');
    return mb_strlen($decoded, 'UTF-8') <= 512 ? $decoded : null;
}

function jpm_normalize_rule(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || $raw[0] !== '/') return null;
    $prefix = str_ends_with($raw, '/*');
    $base = $prefix ? substr($raw, 0, -2) : $raw;
    $path = jpm_normalize_path($base === '' ? '/' : $base);
    if ($path === null) return null;
    return $prefix ? ($path === '/' ? '/*' : $path . '/*') : $path;
}

function jpm_rules_from_text(string $text): array
{
    $lines = preg_split('/\R/u', $text) ?: [];
    $nonEmpty = array_filter($lines, static fn(string $line): bool => trim($line) !== '');
    if (count($nonEmpty) > 50) throw new DomainException(jpm_t('A maximum of 50 path rules is allowed.'));
    $rules = [];
    foreach ($lines as $index => $line) {
        if (trim($line) === '') continue;
        $rule = jpm_normalize_rule($line);
        if ($rule === null) throw new DomainException(jpm_t('Path rule on line %d is invalid.', $index + 1));
        $rules[$rule] = true;
    }
    return array_keys($rules);
}

function jpm_rules_decode(mixed $json): array
{
    if (!is_string($json) || trim($json) === '') return [];
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];
    $rules = [];
    foreach (array_slice($decoded, 0, 50) as $rule) {
        $normalized = is_string($rule) ? jpm_normalize_rule($rule) : null;
        if ($normalized !== null) $rules[$normalized] = true;
    }
    return array_keys($rules);
}

function jpm_rule_matches(string $path, string $rule): bool
{
    if ($rule === '/*') return true;
    if (str_ends_with($rule, '/*')) {
        $prefix = substr($rule, 0, -2);
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
    return $path === $rule;
}

function jpm_is_sensitive_path(?PDO $pdo, string $path): bool
{
    $fixed = ['/static', '/private', '/pondasi', '/api', '/.well-known'];
    foreach ($fixed as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) return true;
    }
    if ($path === '/sw.js' || str_starts_with($path, '/sitemap')) return true;
    if ($pdo instanceof PDO) {
        foreach (['get_admin_path', 'get_login_path', 'get_register_path'] as $resolver) {
            if (!function_exists($resolver)) continue;
            try {
                $dynamic = jpm_normalize_path('/' . ltrim((string)$resolver($pdo), '/'));
                if ($dynamic !== null && ($path === $dynamic || str_starts_with($path, $dynamic . '/'))) return true;
            } catch (Throwable) {
            }
        }
    }
    return false;
}

function jpm_target_matches(array $campaign, string $path, bool $isHomepage): bool
{
    $mode = (string)($campaign['target_mode'] ?? 'all');
    $includes = jpm_rules_decode($campaign['include_rules'] ?? null);
    $excludes = jpm_rules_decode($campaign['exclude_rules'] ?? null);
    foreach ($excludes as $rule) {
        if (jpm_rule_matches($path, $rule)) return false;
    }
    if ($mode === 'homepage') return $isHomepage;
    if ($mode === 'paths') {
        foreach ($includes as $rule) {
            if (jpm_rule_matches($path, $rule)) return true;
        }
        return false;
    }
    return $mode === 'all';
}

function jpm_normalize_target_url(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    if ($raw[0] === '/') {
        if (str_starts_with($raw, '//') || preg_match('/[\x00-\x1F\x7F]/', $raw)) return null;
        return mb_strlen($raw, 'UTF-8') <= 2048 ? $raw : null;
    }
    if (!filter_var($raw, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($raw);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    return mb_strlen($raw, 'UTF-8') <= 2048 ? $raw : null;
}

function jpm_sanitize_html(string $html): string
{
    if (function_exists('cms_sanitize_restricted_html')) return cms_sanitize_restricted_html($html);
    return '';
}

function jpm_parse_datetime(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new DomainException(jpm_t('Enter a valid schedule date and time.'));
    }
    return $date->format('Y-m-d H:i:00');
}
