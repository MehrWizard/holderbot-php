<?php
declare(strict_types=1);

class Input {
    /** Normalize Unicode decimal digits (including Persian/Arabic) accepted by Python int(). */
    public static function decimalDigits(string $value): string {
        if (!preg_match('/^\p{Nd}+$/uD', $value)) return $value;
        return preg_replace_callback('/\p{Nd}/u', function($match) {
            $bytes = array_values(unpack('C*', $match[0]));
            $point = $bytes[0];
            if ($point < 128) return $match[0];
            $point &= (1 << (7 - count($bytes))) - 1;
            for ($i = 1; $i < count($bytes); $i++) $point = ($point << 6) | ($bytes[$i] & 63);
            $distance = 0;
            while (preg_match('/^\p{Nd}$/uD', self::character(--$point))) $distance++;
            return (string)($distance % 10);
        }, $value);
    }
    private static function character(int $c): string {
        if ($c < 128) return chr($c);
        if ($c < 2048) return chr(192 | ($c >> 6)) . chr(128 | ($c & 63));
        if ($c < 65536) return chr(224 | ($c >> 12)) . chr(128 | (($c >> 6) & 63)) . chr(128 | ($c & 63));
        return chr(240 | ($c >> 18)) . chr(128 | (($c >> 12) & 63)) . chr(128 | (($c >> 6) & 63)) . chr(128 | ($c & 63));
    }
}
