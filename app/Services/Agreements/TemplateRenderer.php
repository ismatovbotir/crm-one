<?php

namespace App\Services\Agreements;

use App\Documents\DocumentFormatter;
use App\Enums\Locale;
use App\Support\Money;

/**
 * Renders agreement templates (Spec 06 DEC-10): the template body is sanitized, optional blocks
 * `{{#clause:code}}…{{/clause}}` are kept or removed per clause options, and whitelisted
 * placeholders `{{ group.key }}` are replaced by escaped values from the frozen variables
 * snapshot, formatted for the document language (dates, money, amounts in words). No Blade or PHP
 * ever runs from template text. `{{ order.lines_table }}` expands to a generated, escaped table.
 */
class TemplateRenderer
{
    public const PLACEHOLDER = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)\s*\}\}/';

    public const CLAUSE = '/\{\{#clause:([a-z0-9_]+)\}\}(.*?)\{\{\/clause\}\}/s';

    public function __construct(protected TemplateSanitizer $sanitizer) {}

    /**
     * Placeholder keys used in a body, unique, in order of appearance.
     *
     * @return list<string>
     */
    public function placeholders(string $body): array
    {
        preg_match_all(self::PLACEHOLDER, $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Template problems (BR-AG.1 lint): unknown placeholders, unbalanced or undeclared clauses,
     * and markup the sanitizer would remove.
     *
     * @param  list<string>  $declaredClauses
     * @return list<array{key: string, params: array<string, string>}>
     */
    public function lint(string $body, array $declaredClauses): array
    {
        $errors = [];
        $unknown = array_values(array_diff($this->placeholders($body), AgreementVariables::KEYS));

        if ($unknown !== []) {
            $errors[] = ['key' => 'unknown_placeholders', 'params' => ['keys' => implode(', ', $unknown)]];
        }

        if (preg_match_all('/\{\{#clause:/', $body) !== preg_match_all('/\{\{\/clause\}\}/', $body)) {
            $errors[] = ['key' => 'unbalanced_clauses', 'params' => []];
        }

        preg_match_all(self::CLAUSE, $body, $clauses);
        $undeclared = array_values(array_diff(array_unique($clauses[1]), $declaredClauses));

        if ($undeclared !== []) {
            $errors[] = ['key' => 'undeclared_clauses', 'params' => ['codes' => implode(', ', $undeclared)]];
        }

        if ($this->hasUnsafeMarkup($body)) {
            $errors[] = ['key' => 'unsafe_markup', 'params' => []];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $variables  snapshot from AgreementVariables
     * @param  array<string, bool>  $clauseOptions
     */
    public function render(string $body, array $variables, array $clauseOptions, Locale $locale): string
    {
        $doc = new DocumentFormatter($locale);
        $html = $this->sanitizer->sanitize($body);
        $html = (string) preg_replace_callback(self::CLAUSE, fn (array $match): string => ($clauseOptions[$match[1]] ?? false) ? $match[2] : '', $html);

        return (string) preg_replace_callback(self::PLACEHOLDER, fn (array $match): string => $this->value($match[1], $variables, $doc), $html);
    }

    /**
     * One snapshot value as plain text in the document language (for print footers; escape on output).
     *
     * @param  array<string, mixed>  $variables
     */
    public function text(string $key, array $variables, Locale $locale): string
    {
        $value = $variables[$key] ?? null;

        if (is_array($value)) {
            return $key === 'order.lines_table' ? '' : $this->typed($value, new DocumentFormatter($locale));
        }

        return (string) ($value ?? '');
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function value(string $key, array $variables, DocumentFormatter $doc): string
    {
        if (! in_array($key, AgreementVariables::KEYS, true)) {
            return '';
        }

        $value = $variables[$key] ?? null;

        if ($key === 'order.lines_table') {
            return is_array($value) ? $this->linesTable($value, $doc) : '';
        }

        if (is_array($value)) {
            return e($this->typed($value, $doc));
        }

        return $value === null ? '' : nl2br(e((string) $value), false);
    }

    /**
     * Typed snapshot values: ['t' => money|words|date|longdate|percent|i18n, ...].
     *
     * @param  array<string, mixed>  $value
     */
    protected function typed(array $value, DocumentFormatter $doc): string
    {
        return match ($value['t'] ?? null) {
            'money' => $doc->money(Money::of((int) $value['a'], (string) $value['c'])->toMajor(), (string) $value['c'], Money::exponent((string) $value['c'])),
            'words' => $doc->amountInWords(Money::of((int) $value['a'], (string) $value['c'])->toMajor(), (string) $value['c']),
            'date' => $doc->date($value['v'] ?? null),
            'longdate' => $doc->longDate($value['v'] ?? null),
            'percent' => $doc->percent((string) ($value['v'] ?? '0')),
            'i18n' => (string) (($value['v'][$doc->locale->value] ?? null) ?: collect($value['v'] ?? [])->filter()->first() ?: ''),
            default => '',
        };
    }

    /**
     * @param  array{currency: string, rows: list<array<string, mixed>>, net: int, vat: int, gross: int}  $table
     */
    protected function linesTable(array $table, DocumentFormatter $doc): string
    {
        $locale = $doc->locale->value;
        $currency = $table['currency'];
        $money = fn (int $minor): string => e($doc->money(Money::of($minor, $currency)->toMajor(), $currency, Money::exponent($currency)));
        $head = ['no', 'name', 'unit', 'quantity', 'price', 'net', 'vat', 'gross'];
        $html = '<table><thead><tr>';

        foreach ($head as $column) {
            $html .= '<th>'.e(__('documents.agreement.lines.'.$column, [], $locale)).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($table['rows'] as $index => $row) {
            $html .= '<tr><td>'.($index + 1).'</td><td>'.e((string) $row['name']).($row['option'] ? ' <span>('.e((string) $row['option']).')</span>' : '').'</td>'
                .'<td>'.e((string) $row['unit']).'</td><td>'.e($doc->quantity((string) $row['quantity'])).'</td>'
                .'<td>'.$money((int) $row['price']).'</td><td>'.$money((int) $row['net']).'</td>'
                .'<td>'.($row['vat_exempt'] ? e(__('documents.agreement.lines.vat_exempt', [], $locale)) : e($doc->percent(bcdiv((string) $row['vat_bp'], '100', 2))).' · '.$money((int) $row['vat'])).'</td>'
                .'<td>'.$money((int) $row['gross']).'</td></tr>';
        }

        $html .= '</tbody><tfoot><tr><td colspan="5">'.e(__('documents.agreement.lines.total', [], $locale)).'</td>'
            .'<td>'.$money((int) $table['net']).'</td><td>'.$money((int) $table['vat']).'</td><td>'.$money((int) $table['gross']).'</td></tr></tfoot></table>';

        return $html;
    }

    /** Any tag outside the allowlist, any attribute other than colspan/rowspan, or a comment. */
    protected function hasUnsafeMarkup(string $body): bool
    {
        if (str_contains($body, '<!--')) {
            return true;
        }

        preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)([^>]*)>/', $body, $tags, PREG_SET_ORDER);

        foreach ($tags as [, $tag, $attributes]) {
            if (! in_array(strtolower($tag), TemplateSanitizer::ALLOWED_TAGS, true)) {
                return true;
            }

            preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=|\s|\/|$)/', (string) preg_replace('/[\s\/]+$/', '', ltrim($attributes)), $names);

            foreach ($names[1] as $name) {
                if (! in_array(strtolower($name), TemplateSanitizer::ALLOWED_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
