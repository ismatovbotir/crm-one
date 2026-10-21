<?php

namespace App\Services\Import;

use App\Services\Import\Exceptions\ImportException;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use ZipArchive;

/**
 * Reads a CSV (UTF-8, UTF-8 BOM or Windows-1251; `,` `;` or tab detected) or XLSX (first sheet,
 * zip-bomb guarded) file into rows keyed by the normalised header (lower case, spaces to `_`).
 * Formulas are never evaluated (their text is read and flagged by the caller as invalid).
 * Size and row caps protect the server (roadmap step 7).
 */
class TabularFileReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_ROWS = 5000;

    public const MAX_XLSX_UNCOMPRESSED = 50 * 1024 * 1024;

    /**
     * @return list<array{row_no: int, values: array<string, string>, formula: bool}>
     */
    public function read(string $path, string $originalName): array
    {
        if ((int) filesize($path) > self::MAX_BYTES) {
            throw new ImportException('too_large', ['mb' => self::MAX_BYTES / 1024 / 1024]);
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $head = (string) file_get_contents($path, false, null, 0, 4);

        $records = match (true) {
            $extension === 'xlsx' && str_starts_with($head, "PK\x03\x04") => $this->xlsx($path),
            in_array($extension, ['csv', 'txt'], true) && ! str_starts_with($head, "PK\x03\x04") => $this->csv($path),
            default => throw new ImportException('format'),
        };

        $header = array_map(fn (array $cell): string => (string) preg_replace('/[^a-z0-9_]/', '', str_replace([' ', '-'], '_', mb_strtolower(trim($cell[0])))), array_shift($records) ?? []);

        if ($header === [] || array_filter($header) === []) {
            throw new ImportException('no_header');
        }

        if (count($records) > self::MAX_ROWS) {
            throw new ImportException('too_many_rows', ['max' => self::MAX_ROWS]);
        }

        $rows = [];

        foreach ($records as $index => $cells) {
            if (array_filter($cells, fn (array $cell): bool => trim($cell[0]) !== '') === []) {
                continue;
            }

            $values = [];
            $formula = false;

            foreach ($header as $column => $name) {
                if ($name === '') {
                    continue;
                }

                $values[$name] = trim((string) ($cells[$column][0] ?? ''));
                $formula = $formula || ($cells[$column][1] ?? false);
            }

            $rows[] = ['row_no' => $index + 2, 'values' => $values, 'formula' => $formula];
        }

        return $rows;
    }

    /**
     * @return list<list<array{0: string, 1: bool}>>
     */
    protected function csv(string $path): array
    {
        $content = (string) file_get_contents($path);

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $first = (string) (preg_split('/\r\n|\n|\r/', $content)[0] ?? '');
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $delimiter = $counts[$delimiter] > 0 ? $delimiter : ',';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $records = [];

        while (($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $records[] = array_map(fn (mixed $value): array => [(string) $value, str_starts_with(ltrim((string) $value), '=')], $record);
        }

        fclose($stream);

        return $records;
    }

    /**
     * @return list<list<array{0: string, 1: bool}>>
     */
    protected function xlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new ImportException('format');
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += (int) ($stat['size'] ?? 0);

            if ($zip->numFiles > 50 || $total > self::MAX_XLSX_UNCOMPRESSED || ((int) ($stat['comp_size'] ?? 0) > 0 && ($stat['size'] / max(1, $stat['comp_size'])) > 200)) {
                $zip->close();
                throw new ImportException('format');
            }
        }

        $zip->close();
        $reader = new XlsxReader;
        $reader->open($path);
        $records = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $records[] = array_map(fn (mixed $cell): array => $this->cell($cell), $row->cells);

                    if (count($records) > self::MAX_ROWS + 1) {
                        throw new ImportException('too_many_rows', ['max' => self::MAX_ROWS]);
                    }
                }

                break;
            }
        } finally {
            $reader->close();
        }

        return $records;
    }

    /**
     * @return array{0: string, 1: bool} text and "is formula"
     */
    protected function cell(mixed $cell): array
    {
        return match (true) {
            $cell instanceof Cell\FormulaCell => [(string) $cell->getValue(), true],
            $cell instanceof Cell\NumericCell => [is_float($cell->getValue()) && floor($cell->getValue()) !== $cell->getValue() ? (string) $cell->getValue() : number_format((float) $cell->getValue(), 0, '.', ''), false],
            $cell instanceof Cell\DateTimeCell => [$cell->getValue() instanceof DateTimeInterface ? $cell->getValue()->format('Y-m-d') : '', false],
            $cell instanceof Cell => [is_scalar($cell->getValue()) ? (string) $cell->getValue() : '', false],
            default => ['', false],
        };
    }
}
