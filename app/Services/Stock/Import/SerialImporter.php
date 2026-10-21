<?php

namespace App\Services\Stock\Import;

use App\Actions\Stock\AddReceiptSerials;
use App\Actions\Stock\ClaimSerials;
use App\Models\DeliveryLine;
use App\Models\GoodsReceiptLine;
use App\Models\SerialImportBatch;
use App\Models\SerialImportRow;
use App\Models\User;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\SerialChecker;
use App\Support\BusinessSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Serial import for a receipt line or a delivery note line (Spec 06 21.8): upload → preview (per
 * row result, nothing written to the registry) → commit all-or-nothing (default) or valid rows
 * only, through the same Actions as manual entry (AddReceiptSerials / ClaimSerials). The same file
 * commits once per line (`committed_key`); files are kept privately for the retention period.
 */
class SerialImporter
{
    public const DISK = 'local';

    public function __construct(protected SerialFileReader $reader, protected SerialChecker $checker) {}

    public function preview(GoodsReceiptLine|DeliveryLine $line, UploadedFile $file, User $user): SerialImportBatch
    {
        $hash = (string) hash_file('sha256', $file->getRealPath());
        $context = $this->context($line);
        $earlier = SerialImportBatch::query()->with('committer')->where('committed_key', $context.':'.$line->id.':'.$hash)->first();

        if ($earlier !== null) {
            throw new StockActionException('import_already_committed', ['date' => $earlier->committed_at?->format('d.m.Y H:i') ?? '—', 'user' => $earlier->committer?->name ?? '—']);
        }

        $parsed = $this->reader->read($file->getRealPath(), $file->getClientOriginalName());
        $results = $this->check($line, $parsed['rows'], $user);

        return DB::transaction(function () use ($line, $file, $user, $hash, $context, $parsed, $results): SerialImportBatch {
            $path = $file->storeAs('serial-imports/'.now()->format('Y/m'), Str::random(40).'.'.$parsed['format'], self::DISK);
            $batch = SerialImportBatch::query()->create([
                'context_type' => $context,
                'context_id' => $line->id,
                'file_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                'file_hash' => $hash,
                'disk_path' => $path,
                'size_bytes' => (int) $file->getSize(),
                'format' => $parsed['format'],
                'encoding_detected' => $parsed['encoding'],
                'delimiter_detected' => $parsed['delimiter'],
                'rows_total' => count($results),
                'rows_ok' => collect($results)->where('result', 'ok')->count(),
                'rows_warning' => collect($results)->whereIn('result', ['numeric_cell', 'already_in_document'])->count(),
                'rows_error' => collect($results)->whereNotIn('result', ['ok', 'numeric_cell', 'already_in_document'])->count(),
                'status' => 'validated',
                'created_by' => $user->id,
                'expires_at' => now()->addDays(BusinessSettings::int('serials.import_file_retention_days')),
            ]);

            foreach ($results as $row) {
                SerialImportRow::query()->create(['batch_id' => $batch->id, ...$row]);
            }

            return $batch;
        });
    }

    /**
     * @return list<array{raw: string, serial: string|null, result: string, message: string}>
     */
    public function commit(SerialImportBatch $batch, GoodsReceiptLine|DeliveryLine $line, string $mode, User $user): array
    {
        if ($batch->status !== 'validated' || $batch->context_type !== $this->context($line) || $batch->context_id !== $line->id) {
            throw new StockActionException('import_not_ready');
        }

        if ($mode === 'all_or_nothing' && $batch->rows_error > 0) {
            throw new StockActionException('import_has_errors', ['count' => $batch->rows_error]);
        }

        $accepted = $batch->rows()->whereIn('result', ['ok', 'numeric_cell'])->whereNotNull('serial_normalized')->get(['serial_normalized', 'is_damaged', 'note']);
        $inputs = $accepted->pluck('serial_normalized')->values()->all();
        $damaged = $accepted->where('is_damaged', true)->mapWithKeys(fn ($row): array => [(string) $row->serial_normalized => $row->note])->all();

        $results = $line instanceof GoodsReceiptLine
            ? app(AddReceiptSerials::class)->handle($line, $inputs, $user, $batch->id, $mode === 'all_or_nothing', $damaged)
            : app(ClaimSerials::class)->handle($line, $inputs, $user, $batch->id, $mode === 'all_or_nothing');

        $failed = collect($results)->whereNotIn('result', ['ok', 'already_in_document'])->count();

        if ($mode === 'all_or_nothing' && $failed > 0) {
            throw new StockActionException('import_revalidate');
        }

        $batch->forceFill(['status' => 'committed', 'mode' => $mode, 'committed_by' => $user->id, 'committed_at' => now(), 'committed_key' => $batch->context_type.':'.$batch->context_id.':'.$batch->file_hash])->save();

        return $results;
    }

    /**
     * @param  list<array{row_no: int, serial: string|null, warranty_until: string|null, note: string|null, flag: string|null}>  $rows
     * @return list<array{row_no: int, serial_raw: string|null, serial_normalized: string|null, warranty_until: string|null, note: string|null, result: string, message: string}>
     */
    protected function check(GoodsReceiptLine|DeliveryLine $line, array $rows, User $user): array
    {
        $prepared = $this->checker->prepare(array_map(fn (array $row): string => (string) $row['serial'], $rows));
        $serials = array_values(array_unique(array_filter(array_column(array_filter($prepared, fn (array $p): bool => $p['result'] === 'ok'), 'serial'))));
        $checks = $line instanceof GoodsReceiptLine
            ? $this->checker->forReceipt($line->receipt, $line->item, $serials)
            : $this->checker->forDelivery($line->delivery, $line, $serials, $user);

        $results = [];

        foreach ($rows as $index => $row) {
            $p = $prepared[$index];
            [$result, $message] = [$p['result'], $p['message']];

            if ($row['flag'] === 'formula' || $row['flag'] === 'precision_lost') {
                [$result, $message] = [$row['flag'], __('stock.results.'.$row['flag'])];
            } elseif ($result === 'ok' && isset($checks[$p['serial']])) {
                [$result, $message] = [$checks[$p['serial']]['result'], $checks[$p['serial']]['message']];

                if ($result === 'ok' && $row['flag'] === 'numeric_cell') {
                    [$result, $message] = ['numeric_cell', __('stock.results.numeric_cell')];
                }
            }

            $results[] = [
                'row_no' => $row['row_no'],
                'serial_raw' => mb_substr((string) $row['serial'], 0, 120),
                'serial_normalized' => $p['serial'],
                'warranty_until' => $row['warranty_until'],
                'note' => $row['note'],
                'is_damaged' => $line instanceof GoodsReceiptLine && ($row['damaged'] ?? false),
                'result' => $result,
                'message' => mb_substr((string) $message, 0, 250),
            ];
        }

        return $results;
    }

    protected function context(GoodsReceiptLine|DeliveryLine $line): string
    {
        return $line instanceof GoodsReceiptLine ? 'goods_receipt_line' : 'delivery_line';
    }
}
