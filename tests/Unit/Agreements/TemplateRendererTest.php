<?php

namespace Tests\Unit\Agreements;

use App\Enums\Locale;
use App\Services\Agreements\TemplateRenderer;
use App\Services\Agreements\TemplateSanitizer;
use Tests\TestCase;

/**
 * Spec 06 DEC-10 / section 13 "Templates": escaped whitelisted placeholders, clause blocks, lint,
 * sanitizer allowlist, locale formatting.
 */
class TemplateRendererTest extends TestCase
{
    private function renderer(): TemplateRenderer
    {
        return new TemplateRenderer(new TemplateSanitizer);
    }

    public function test_values_are_escaped_and_formatted_per_language(): void
    {
        $variables = [
            'customer.legal_name' => '<script>alert(1)</script> & Co',
            'order.total' => ['t' => 'money', 'a' => 123456789, 'c' => 'UZS'],
            'order.total_in_words' => ['t' => 'words', 'a' => 100000, 'c' => 'UZS'],
            'agreement.starts_on' => ['t' => 'longdate', 'v' => '2026-10-16'],
            'company.legal_name' => ['t' => 'i18n', 'v' => ['uz' => 'RSG MChJ', 'ru' => 'ООО RSG', 'en' => 'RSG LLC']],
        ];
        $body = '<p>{{ customer.legal_name }} · {{ order.total }} · {{ order.total_in_words }} · {{ agreement.starts_on }} · {{ company.legal_name }} · {{ order.secret }}</p>';

        $ru = $this->renderer()->render($body, $variables, [], Locale::Ru);
        $en = $this->renderer()->render($body, $variables, [], Locale::En);

        $this->assertStringNotContainsString('<script>', $ru);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $ru);
        $this->assertStringContainsString("1\u{00A0}234\u{00A0}567,89\u{00A0}UZS", $ru);
        $this->assertStringContainsString('1,234,567.89', $en);
        $this->assertStringContainsString('ООО RSG', $ru);
        $this->assertStringContainsString('RSG LLC', $en);
        $this->assertStringContainsString('October 16, 2026', $en);
        $this->assertStringNotContainsString('{{', $ru);
    }

    public function test_clause_blocks_follow_the_options(): void
    {
        $body = '<p>A</p>{{#clause:penalty}}<p>Penalty</p>{{/clause}}{{#clause:nda}}<p>NDA</p>{{/clause}}';
        $html = $this->renderer()->render($body, [], ['penalty' => true, 'nda' => false], Locale::En);

        $this->assertStringContainsString('Penalty', $html);
        $this->assertStringNotContainsString('NDA', $html);
    }

    public function test_lint_reports_unknown_placeholders_clauses_and_unsafe_markup(): void
    {
        $lint = $this->renderer()->lint('<p onclick="x()">{{ order.number }} {{ customer.password }}</p>{{#clause:nda}}x{{/clause}}{{#clause:penalty}}', ['penalty']);
        $keys = array_column($lint, 'key');

        $this->assertContains('unknown_placeholders', $keys);
        $this->assertContains('unbalanced_clauses', $keys);
        $this->assertContains('undeclared_clauses', $keys);
        $this->assertContains('unsafe_markup', $keys);
        $this->assertSame('customer.password', $lint[array_search('unknown_placeholders', $keys, true)]['params']['keys']);
        $this->assertSame([], $this->renderer()->lint('<h1>T</h1><table><tr><td colspan="2">{{ order.number }}</td></tr></table>', []));
    }

    public function test_sanitizer_keeps_formatting_and_drops_active_content(): void
    {
        $clean = (new TemplateSanitizer)->sanitize('<h2 style="color:red">Title</h2><p onclick="steal()">Text <a href="javascript:x">link</a> ‘quote’</p><script>evil()</script><iframe src="x"></iframe><img src=x onerror=alert(1)><td colspan="2" rowspan="x">c</td>');

        $this->assertStringContainsString('<h2>Title</h2>', $clean);
        $this->assertStringContainsString('<p>Text link ‘quote’</p>', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        $this->assertStringNotContainsString('rowspan', $clean);
    }
}
