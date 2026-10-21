<?php

namespace App\Http\Controllers;

use App\Services\Import\CustomerImporter;
use App\Services\Import\ItemImporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV templates for the go-live import (roadmap step 7): header row plus one example row.
 */
class ImportTemplateController extends Controller
{
    public function __invoke(string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['customers', 'items'], true), 404);

        $columns = $kind === 'items' ? ItemImporter::COLUMNS : CustomerImporter::COLUMNS;
        $example = $kind === 'items'
            ? ['9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', '00-00009999', 'ART-1', 'Example router', 'product', 'pcs', '12', '1500000.00', 'UZS', '1100000.00', 'UZS', 'no', 'yes', '', '', '']
            : ['Example LLC', 'Example MChJ', '301234567', '+998901234567', 'info@example.uz', 'Tashkent', 'Amir Temur 1', 'UZS', '30', 'b2b', '', 'Ali', 'Valiyev', 'Director', '+998901112233', 'ali@example.uz'];

        return response()->streamDownload(function () use ($columns, $example): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';');
            fputcsv($out, $example, ';');
            fclose($out);
        }, $kind.'-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
