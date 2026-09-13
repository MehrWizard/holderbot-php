<?php
declare(strict_types=1);

/** A cron slice bounds every HTTP call, including nested authentication calls. */
class RequestBudget {
    public static ?float $deadline = null;
    public static function milliseconds(int $normalSeconds): int {
        if (self::$deadline === null) return $normalSeconds * 1000;
        $remaining = (int)((self::$deadline - microtime(true)) * 1000);
        if ($remaining < 100) throw new RuntimeException('Worker time budget exhausted');
        return min($normalSeconds * 1000, $remaining);
    }
}
