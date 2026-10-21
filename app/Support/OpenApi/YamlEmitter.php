<?php

namespace App\Support\OpenApi;

/**
 * Minimal YAML 1.2 emitter for the OpenAPI document (no dependency): maps, lists, scalars.
 * Strings are emitted as JSON-style double-quoted scalars (valid YAML) or as literal blocks
 * when they span several lines; keys are quoted unless they are plain identifiers.
 */
final class YamlEmitter
{
    public static function dump(mixed $value): string
    {
        return self::node($value, 0)."\n";
    }

    private static function node(mixed $value, int $indent): string
    {
        if (! is_array($value)) {
            return self::scalar($value, $indent);
        }

        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('  ', $indent);
        $lines = [];

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $lines[] = $pad.'- '.ltrim(is_array($item) && $item !== [] ? self::node($item, $indent + 1) : self::node($item, $indent + 1));
            }

            return implode("\n", $lines);
        }

        foreach ($value as $key => $item) {
            $prefix = $pad.self::key((string) $key).':';

            if (is_array($item) && $item !== []) {
                $lines[] = $prefix."\n".self::node($item, $indent + 1);
            } else {
                $lines[] = $prefix.' '.self::node($item, $indent + 1);
            }
        }

        return implode("\n", $lines);
    }

    private static function key(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $key) === 1 ? $key : (string) json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function scalar(mixed $value, int $indent): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) && str_contains($value, "\n") => "|-\n".implode("\n", array_map(fn (string $line): string => $line === '' ? '' : str_repeat('  ', $indent).$line, explode("\n", $value))),
            default => (string) json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
