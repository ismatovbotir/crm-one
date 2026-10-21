<?php

namespace App\Services\Reports;

use App\Http\Controllers\TenderPrintController;
use App\Models\User;
use App\Support\Money;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV and XLSX export of a report (roadmap step 3). Money columns become two columns (amount in
 * major units as a plain number, currency code); percentages and integers stay numbers; text cells
 * are protected against spreadsheet formula injection. CSV is UTF-8 with BOM and `;` separators.
 */
class ReportExporter
{
    public const MAX_ROWS = 100_000;

    /**
     * @param  array{from: string|null, to: string|null}  $filters
     */
    public function download(Report $report, User $user, array $filters, string $format): StreamedResponse
    {
        $name = $report->key().'-'.now()->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($report, $user, $filters, $format): void {
            $rows = $this->rows($report, $user, $filters);

            if ($format === 'xlsx') {
                $writer = new Writer;
                $writer->openToFile('php://output');

                foreach ($rows as $row) {
                    $writer->addRow(self::xlsxRow($row));
                }

                $writer->close();

                return;
            }

            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (mixed $value): string => is_string($value) ? TenderPrintController::csvSafe($value) : (string) ($value ?? ''), $row), ';');
            }

            fclose($out);
        }, $name, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * XLSX row in which text is always a text cell: OpenSpout turns any string starting with "="
     * into a live formula, so text from customer data could otherwise run in Excel (security
     * audit 3, roadmap step 9).
     *
     * @param  list<string|int|float|null>  $values
     */
    public static function xlsxRow(array $values): Row
    {
        return new Row(array_map(fn (mixed $value): Cell => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value), array_values($values)));
    }

    /**
     * Header row and data rows ready to write.
     *
     * @param  array{from: string|null, to: string|null}  $filters
     * @return \Generator<int, list<string|int|float|null>>
     */
    public function rows(Report $report, User $user, array $filters): \Generator
    {
        $header = [];

        foreach ($report->columns() as $column) {
            $label = __('reports.columns.'.$column['key']);
            $header[] = $label;

            if ($column['type'] === 'money') {
                $header[] = $label.' · '.__('reports.columns.currency');
            }
        }

        yield $header;
        $count = 0;

        foreach ($report->rows($user, $filters) as $row) {
            if (++$count > self::MAX_ROWS) {
                break;
            }

            $line = [];

            foreach ($report->columns() as $column) {
                $value = $row[$column['key']] ?? null;

                if ($column['type'] === 'money') {
                    $line[] = is_array($value) ? (float) Money::of((int) $value['minor'], (string) $value['currency'])->toMajor() : null;
                    $line[] = is_array($value) ? (string) $value['currency'] : null;
                } else {
                    $line[] = is_int($value) || is_float($value) ? $value : ($value === null ? null : (string) $value);
                }
            }

            yield $line;
        }
    }
}
