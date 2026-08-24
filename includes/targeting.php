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
    $fixed = ['/static', '/private', '/pondasi', '/api', '/.well-known', '/popup-manager'];
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

function jpm_target_matches(array $campaign, string $path, bool $isHomepage, ?string $context = null): bool
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
    if ($mode === 'contexts') {
        $context ??= function_exists('jpm_current_context') ? jpm_current_context($path) : 'custom';
        return in_array($context, jpm_context_rules_decode($campaign['context_rules'] ?? null), true);
    }
    return $mode === 'all';
}

function jpm_context_options(): array
{
    return [
        'home' => jpm_t('Homepage'),
        'search' => jpm_t('Search results'),
        'error.404' => jpm_t('404 error page'),
        'list.article' => jpm_t('Article list'),
        'list.page' => jpm_t('Page list'),
        'list.category_index' => jpm_t('Category index'),
        'list.category' => jpm_t('Category archive'),
        'list.author_index' => jpm_t('Author index'),
        'list.author' => jpm_t('Author posts'),
        'list.archive' => jpm_t('Date archive'),
        'single.article' => jpm_t('Single article'),
        'single.page' => jpm_t('Single page'),
        'single.theme' => jpm_t('Theme Template page'),
        'custom' => jpm_t('Other plugin or custom route'),
    ];
}

function jpm_context_rules_from_input(mixed $input): array
{
    $values = is_array($input) ? $input : [];
    $allowed = jpm_context_options();
    $result = [];
    foreach (array_slice($values, 0, count($allowed)) as $value) {
        if (is_string($value) && isset($allowed[$value])) $result[$value] = true;
    }
    return array_keys($result);
}

function jpm_context_rules_decode(mixed $json): array
{
    if (!is_string($json) || trim($json) === '') return [];
    return jpm_context_rules_from_input(json_decode($json, true));
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

function jpm_trusted_url_is_safe(string $value, bool $allowContact = false, bool $allowFragment = false): bool
{
    $value = trim($value);
    if ($value === '' || mb_strlen($value, 'UTF-8') > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value)) return false;
    if ($allowFragment && $value[0] === '#') return true;
    if ($value[0] === '/') return !str_starts_with($value, '//');
    if ($allowContact && preg_match('#^(mailto:|tel:)#i', $value) === 1) return true;
    if (!filter_var($value, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($value);
    return is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
}

function jpm_trusted_css_is_safe(string $css): bool
{
    return preg_match('/(?:expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding\s*:)/i', $css) !== 1;
}

function jpm_sanitize_trusted_html(string $html): string
{
    $html = trim($html);
    if ($html === '' || !class_exists('DOMDocument')) return '';
    $allowedTags = array_fill_keys([
        'p','br','hr','strong','b','em','i','u','s','blockquote','pre','code',
        'h1','h2','h3','h4','h5','h6','ul','ol','li','a','img','picture','source',
        'figure','figcaption','table','thead','tbody','tfoot','tr','th','td','span','div',
        'section','article','header','footer','main','aside','nav','video','audio','iframe','style',
    ], true);
    $allowedAttrs = [
        '*' => ['class','id','style','title','role','aria-label','aria-hidden'],
        'a' => ['href','target','rel'],
        'img' => ['src','alt','width','height','loading','decoding'],
        'source' => ['src','type','media'],
        'iframe' => ['src','width','height','loading','allow','allowfullscreen','referrerpolicy','frameborder'],
        'video' => ['src','width','height','controls','autoplay','muted','loop','playsinline','poster','preload'],
        'audio' => ['src','controls','autoplay','muted','loop','preload'],
        'th' => ['colspan','rowspan','scope'],
        'td' => ['colspan','rowspan'],
    ];
    $blockedTags = ['script','object','embed','link','meta','form','input','button','textarea','select','option','svg','canvas'];
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//comment()') as $comment) $comment->parentNode?->removeChild($comment);

    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs, $blockedTags): void {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);
            if (in_array($tag, $blockedTags, true)) {
                $node->parentNode?->removeChild($node);
                return;
            }
            if (!isset($allowedTags[$tag])) {
                $parent = $node->parentNode;
                if (!$parent) return;
                $children = [];
                while ($node->firstChild) {
                    $child = $node->firstChild;
                    $parent->insertBefore($child, $node);
                    $children[] = $child;
                }
                $parent->removeChild($node);
                foreach ($children as $child) $walk($child);
                return;
            }
            if ($tag === 'style' && !jpm_trusted_css_is_safe((string)$node->textContent)) {
                $node->parentNode?->removeChild($node);
                return;
            }
            $remove = [];
            foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->name);
                $allowed = array_merge($allowedAttrs['*'], $allowedAttrs[$tag] ?? []);
                if (str_starts_with($name, 'on') || $name === 'srcdoc' || !in_array($name, $allowed, true)) {
                    $remove[] = $attribute->name;
                    continue;
                }
                $value = trim((string)$attribute->value);
                if ($name === 'style' && !jpm_trusted_css_is_safe($value)) $remove[] = $attribute->name;
                if ($name === 'href' && !jpm_trusted_url_is_safe($value, true, true)) $remove[] = $attribute->name;
                if (in_array($name, ['src', 'poster'], true) && !jpm_trusted_url_is_safe($value)) $remove[] = $attribute->name;
            }
            foreach (array_unique($remove) as $attributeName) $node->removeAttribute($attributeName);
            if ($tag === 'a' && strtolower($node->getAttribute('target')) === '_blank') $node->setAttribute('rel', 'noopener noreferrer');
            if ($tag === 'iframe' && !$node->hasAttribute('loading')) $node->setAttribute('loading', 'lazy');
        }
        $children = [];
        foreach ($node->childNodes as $child) $children[] = $child;
        foreach ($children as $child) $walk($child);
    };
    $walk($document);
    $result = trim((string)$document->saveHTML());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $result;
}

function jpm_sanitize_html(string $html, string $policy = 'restricted'): string
{
    if ($policy === 'trusted') return jpm_sanitize_trusted_html($html);
    if (function_exists('cms_sanitize_restricted_html')) return cms_sanitize_restricted_html($html);
    return '';
}

function jpm_html_has_content(string $html): bool
{
    return trim(strip_tags($html)) !== '' || preg_match('/<(?:img|iframe|video|audio|hr|table)\b/i', $html) === 1;
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
