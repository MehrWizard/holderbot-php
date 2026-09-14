<?php
declare(strict_types=1);

/** Parse a JSON array incrementally; callers supply a bounded byte reader. */
final class ImportReader {
    public static function page(callable $read, int $offset, bool $started, int $limit = 50): array {
        $buffer = ''; $cursor = 0; $items = [];
        $peek = function () use (&$buffer, &$cursor, &$offset, $read): string {
            if ($cursor >= strlen($buffer)) { $buffer = $read($offset, 65536); $cursor = 0; }
            return $buffer[$cursor] ?? '';
        };
        $take = function () use ($peek, &$cursor, &$offset): string { $c = $peek(); if ($c !== '') { $cursor++; $offset++; } return $c; };
        $space = function () use ($peek, $take): void { while (in_array($peek(), [" ", "\r", "\n", "\t"], true)) $take(); };
        if (!$started) { $space(); if ($take() !== '[') throw new InvalidArgumentException('Import must be an array'); }
        while (count($items) < $limit) {
            $space();
            $c = $peek();
            if ($c === ']') {
                if ($started && !$items) throw new InvalidArgumentException('Trailing comma in import');
                $take(); $space(); if ($peek() !== '') throw new InvalidArgumentException('Trailing import data');
                return ['items'=>$items, 'offset'=>$offset, 'done'=>true];
            }
            if ($take() !== '{') throw new InvalidArgumentException('Import entries must be objects');
            $json = '{'; $depth = 1; $quoted = false; $escape = false;
            while ($depth > 0) {
                $c = $take(); if ($c === '') throw new InvalidArgumentException('Incomplete import entry');
                $json .= $c;
                if (strlen($json) > 65536) throw new InvalidArgumentException('Import entry exceeds 64 KiB');
                if ($quoted) { if ($escape) $escape = false; elseif ($c === '\\') $escape = true; elseif ($c === '"') $quoted = false; }
                elseif ($c === '"') $quoted = true;
                elseif ($c === '{' || $c === '[') $depth++;
                elseif ($c === '}' || $c === ']') $depth--;
            }
            $items[] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $space(); $separator = $take();
            if ($separator === ']') { $space(); if ($peek() !== '') throw new InvalidArgumentException('Trailing import data'); return ['items'=>$items,'offset'=>$offset,'done'=>true]; }
            if ($separator !== ',') throw new InvalidArgumentException('Missing import separator');
            $space(); if ($peek() === ']') throw new InvalidArgumentException('Trailing comma in import');
            $started = true;
        }
        return ['items'=>$items,'offset'=>$offset,'done'=>false];
    }
}
