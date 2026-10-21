<?php

namespace App\Services\Stock;

/**
 * Compact serial lists for prints (Spec 06 21.10): consecutive runs (same prefix, numeric suffix
 * of equal width, step 1) collapse into ranges; single values stay as they are.
 */
class SerialRangeFormatter
{
    /**
     * @param  list<string>  $serials
     * @return list<array{from: string, to: string, count: int}>
     */
    public function ranges(array $serials): array
    {
        $serials = array_values(array_unique($serials));
        sort($serials, SORT_STRING);
        $ranges = [];

        foreach ($serials as $serial) {
            $last = $ranges === [] ? null : $ranges[array_key_last($ranges)];

            if ($last !== null && $this->follows($last['to'], $serial)) {
                $ranges[array_key_last($ranges)] = ['from' => $last['from'], 'to' => $serial, 'count' => $last['count'] + 1];

                continue;
            }

            $ranges[] = ['from' => $serial, 'to' => $serial, 'count' => 1];
        }

        return $ranges;
    }

    protected function follows(string $previous, string $next): bool
    {
        if (preg_match('/^(.*?)(\d+)$/', $previous, $a) !== 1 || preg_match('/^(.*?)(\d+)$/', $next, $b) !== 1) {
            return false;
        }

        return $a[1] === $b[1] && strlen($a[2]) === strlen($b[2]) && bccomp(bcadd($a[2], '1'), $b[2]) === 0;
    }
}
