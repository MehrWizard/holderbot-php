<?php
declare(strict_types=1);

/** Overrides use the same names as Python MessageTexts / KeyboardTexts. */
class Language {
    private static ?array $defaults = null;
    public static function get(string $group, string $key, ?string $fallback = null): string {
        global $config;
        self::$defaults ??= json_decode(file_get_contents(__DIR__ . '/language.json'), true, flags: JSON_THROW_ON_ERROR);
        $override = $config[$group][$key] ?? getenv($key);
        if (is_string($override) && $override !== '') return $override;
        return $fallback ?? self::$defaults[$group][$key] ?? '';
    }
    public static function replace(string $group, string $text): string {
        self::$defaults ??= json_decode(file_get_contents(__DIR__ . '/language.json'), true, flags: JSON_THROW_ON_ERROR);
        $key = array_search($text, self::$defaults[$group], true);
        return $key === false ? $text : self::get($group, $key, $text);
    }
}
