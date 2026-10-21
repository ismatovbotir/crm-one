<?php

namespace App\Services\Stock\Import;

use App\Services\Stock\Exceptions\StockActionException;
use App\Support\BusinessSettings;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use ZipArchive;

/**
 * Reads serial files (Spec 06 21.8): CSV (native; UTF-8 with or without BOM, else Windows-1251;
 * delimiter detected among , ; tab) and XLSX (openspout streaming reader, no formula engine). The
 * serial is always read as text: numeric Excel cells are converted only when integral and ≤ 15
 * digits (flag `numeric_cell`), longer ones are errors, formulas are never trusted. Header row
 * required (`serial_number` or its translation); a single-column file needs no header name.
 */
class SerialFileReader
{
    public const MAX_XLSX_UNCOMPRESSED = 50 * 1024 * 1024;

    public const MAX_XLSX_ENTRIES = 50;

    /**
     * @return array{format: string, encoding: string|null, delimiter: string|null, rows: list<array{row_no: int, serial: string|null, warranty_until: string|null, note: string|null, damaged: bool, flag: string|null}>}
     */
    public function read(string $path, string $originalName): array
    {
        $size = (int) filesize($path);

        if ($size > BusinessSettings::int('serials.import_max_mb') * 1024 * 1024) {
            throw new StockActionException('import_too_large', ['max' => BusinessSettings::int('serials.import_max_mb')]);
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $head = (string) file_get_contents($path, false, null, 0, 4);

        if ($extension === 'xlsx' && str_starts_with($head, "PK\x03\x04")) {
            $result = $this->readXlsx($path);
        } elseif (in_array($extension, ['csv', 'txt'], true) && ! str_starts_with($head, "PK\x03\x04")) {
            $result = $this->readCsv($path);
        } else {
            throw new StockActionException('import_format');
        }

        if (count($result['rows']) > BusinessSettings::int('serials.import_max_rows')) {
            throw new StockActionException('import_too_many_rows', ['max' => BusinessSettings::int('serials.import_max_rows')]);
        }

        return $result;
    }

    /**
     * @return array{format: string, encoding: string|null, delimiter: string|null, rows: list<array{row_no: int, serial: string|null, warranty_until: string|null, note: string|null, damaged: bool, flag: string|null}>}
     */
    protected function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $encoding = 'UTF-8';

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
            $encoding = 'UTF-8 BOM';
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
            $encoding = 'Windows-1251';
        }

        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $first = (string) ($lines[0] ?? '');
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $delimiter = $counts[$delimiter] > 0 ? $delimiter : ',';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $records = [];

        while (($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $records[] = array_map(fn (mixed $value): string => (string) $value, $record);
        }

        fclose($stream);

        return ['format' => 'csv', 'encoding' => $encoding, 'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter, 'rows' => $this->map($records, fn (string $value): array => [$value, null])];
    }

    /**
     * @return array{format: string, encoding: string|null, delimiter: string|null, rows: list<array{row_no: int, serial: string|null, warranty_until: string|null, note: string|null, damaged: bool, flag: string|null}>}
     */
    protected function readXlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new StockActionException('import_format');
        }

        $total = 0;

        if ($zip->numFiles > self::MAX_XLSX_ENTRIES) {
            $zip->close();
            throw new StockActionException('import_zip_guard');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += (int) ($stat['size'] ?? 0);

            if ($total > self::MAX_XLSX_UNCOMPRESSED || ((int) ($stat['comp_size'] ?? 0) > 0 && ($stat['size'] / max(1, $stat['comp_size'])) > 200)) {
                $zip->close();
                throw new StockActionException('import_zip_guard');
            }
        }

        $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();

        if (! $hasContentTypes) {
            throw new StockActionException('import_format');
        }

        $reader = new XlsxReader;
        $reader->open($path);
        $records = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $records[] = $row->cells;
                }

                break;
            }
        } finally {
            $reader->close();
        }

        return ['format' => 'xlsx', 'encoding' => null, 'delimiter' => null, 'rows' => $this->map($records, fn (mixed $cell): array => $this->cellText($cell))];
    }

    /**
     * @return array{0: string, 1: string|null} text and flag
     */
    protected function cellText(mixed $cell): array
    {
        if ($cell instanceof Cell\FormulaCell) {
            return [$cell->getValue(), 'formula'];
        }

        if ($cell instanceof Cell\NumericCell) {
            $value = $cell->getValue();

            if (is_float($value) && floor($value) !== $value) {
                return [(string) $value, 'numeric_cell'];
            }

            $digits = number_format((float) $value, 0, '.', '');

            return strlen(ltrim($digits, '-')) > 15 ? [$digits, 'precision_lost'] : [$digits, 'numeric_cell'];
        }

        if ($cell instanceof Cell\DateTimeCell) {
            $value = $cell->getValue();

            return [$value instanceof DateTimeInterface ? $value->format('Y-m-d') : '', null];
        }

        if ($cell instanceof Cell) {
            $value = $cell->getValue();

            return [is_scalar($value) ? (string) $value : '', null];
        }

        return ['', null];
    }

    /**
     * @param  list<array<int, mixed>>  $records
     * @param  callable(mixed): array{0: string, 1: string|null}  $text
     * @return list<array{row_no: int, serial: string|null, warranty_until: string|null, note: string|null, damaged: bool, flag: string|null}>
     */
    protected function map(array $records, callable $text): array
    {
        if ($records === []) {
            return [];
        }

        $header = array_map(fn (mixed $cell): string => mb_strtolower(trim($text($cell)[0])), $records[0]);
        $columns = $this->columns($header);
        $start = $columns['serial'] === null ? 0 : 1;
        $serialColumn = $columns['serial'] ?? 0;
        $rows = [];

        foreach (array_slice($records, $start) as $index => $record) {
            [$serial, $flag] = isset($record[$serialColumn]) ? $text($record[$serialColumn]) : ['', null];
            $warranty = $columns['warranty_until'] !== null && isset($record[$columns['warranty_until']]) ? trim($text($record[$columns['warranty_until']])[0]) : '';
            $note = $columns['note'] !== null && isset($record[$columns['note']]) ? trim($text($record[$columns['note']])[0]) : '';
            $damaged = $columns['damaged'] !== null && isset($record[$columns['damaged']]) && self::isDamagedMark(trim($text($record[$columns['damaged']])[0]));

            if (trim($serial) === '' && $warranty === '' && $note === '') {
                continue;
            }

            $rows[] = [
                'row_no' => $index + 1 + $start,
                'serial' => $serial,
                'warranty_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $warranty) === 1 ? $warranty : null,
                'note' => $note !== '' ? mb_substr($note, 0, 250) : null,
                'damaged' => $damaged,
                'flag' => $flag,
            ];
        }

        return $rows;
    }

    /**
     * Header positions: canonical English keys or their translations in any language.
     *
     * @param  list<string>  $header
     * @return array{serial: int|null, warranty_until: int|null, note: int|null, damaged: int|null}
     */
    protected function columns(array $header): array
    {
        $find = function (string $key) use ($header): ?int {
            $names = [$key];

            foreach (['uz', 'ru', 'en'] as $locale) {
                $names[] = mb_strtolower((string) __('stock.import.columns.'.$key, [], $locale));
            }

            foreach ($header as $position => $name) {
                if (in_array($name, $names, true)) {
                    return $position;
                }
            }

            return null;
        };

        return ['serial' => $find('serial_number'), 'warranty_until' => $find('warranty_until'), 'note' => $find('note'), 'damaged' => $find('damaged')];
    }

    /**
     * Optional `damaged` column (Spec 06 26.4): 1 / yes / true / x / "damaged" in uz, ru or en marks the unit damaged.
     */
    public static function isDamagedMark(string $value): bool
    {
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return false;
        }

        $marks = ['1', 'x', '+', 'yes', 'true', 'y', 'да', 'ha', 'damaged'];

        foreach (['uz', 'ru', 'en'] as $locale) {
            $marks[] = mb_strtolower((string) __('stock.import.columns.damaged', [], $locale));
            $marks[] = mb_strtolower((string) __('stock.import.damaged_mark', [], $locale));
        }

        return in_array($value, $marks, true);
    }
}
