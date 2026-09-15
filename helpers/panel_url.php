<?php
declare(strict_types=1);

final class PanelUrl {
    /**
     * Canonicalize a panel base URL while retaining a reverse-proxy path.
     * Explicit default ports avoid inconsistent proxy/hosting URL handling.
     */
    public static function normalize(string $url): ?string {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) return null;
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return null;

        $host = (string)$parts['host'];
        if ($host === '') return null;
        if (str_contains($host, ':') && $host[0] !== '[') $host = '[' . $host . ']';
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) return null;

        $path = (string)($parts['path'] ?? '');
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        $path = $path === '/' ? '' : rtrim($path, '/');
        return $scheme . '://' . $host . ':' . $port . $path;
    }

    /**
     * Candidate API bases for an origin, reverse-proxy base, or dashboard URL.
     * Login is read-only, so walking at most five parent paths is safe.
     */
    public static function candidates(string $url): array {
        $normalized = self::normalize($url);
        if ($normalized === null) return [];
        $parts = parse_url($normalized);
        $origin = $parts['scheme'] . '://' . (str_contains((string)$parts['host'], ':') ? '[' . $parts['host'] . ']' : $parts['host']) . ':' . $parts['port'];
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $result = [$normalized];
        for ($attempt = 0; $attempt < 5 && $path !== ''; $attempt++) {
            $path = rtrim(dirname($path), '/.');
            $candidate = $origin . ($path === '' ? '' : '/' . ltrim($path, '/'));
            if (!in_array($candidate, $result, true)) $result[] = $candidate;
        }
        if (!in_array($origin, $result, true)) $result[] = $origin;
        return $result;
    }
}
