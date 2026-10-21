<?php

namespace Tests\Feature;

use App\Actions\Orders\ConfirmOrder;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Livewire\Reports\Show;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Roadmap step 3: reports per permission, figures scoped to the user, CSV (BOM, formula-safe,
 * money as major + currency) and XLSX export.
 */
class ReportsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public function test_reports_follow_permissions(): void
    {
        $keys = fn (User $user): array => array_map(fn ($report) => $report->key(), app(ReportRegistry::class)->allowedFor($user));

        $salesKeys = $keys(User::factory()->sales()->create());
        $this->assertSame(['sales_funnel', 'offers_win_rate', 'orders_backlog'], array_slice($salesKeys, 0, 3));
        $this->assertNotContains('payments', $salesKeys);
        $this->assertNotContains('stock_serials', $salesKeys);
        $this->assertContains('receivables_aging', $keys(User::factory()->accountant()->create()));
        $this->assertContains('payments', $keys(User::factory()->accountant()->create()));
        $this->assertNotContains('sales_funnel', $keys(User::factory()->accountant()->create()));
        $this->assertContains('stock_serials', $keys(User::factory()->stockManager()->create()));

        $sales = User::factory()->sales()->create();
        $this->actingAs($sales)->get(route('reports.index'))->assertOk()->assertSee(__('reports.list.sales_funnel.title'));
        $this->actingAs($sales)->get(route('reports.show', 'payments'))->assertForbidden();
        $this->actingAs($sales)->get(route('reports.export', ['report' => 'payments', 'format' => 'csv']))->assertForbidden();
        $this->actingAs($sales)->get(route('reports.show', 'nope'))->assertNotFound();
    }

    public function test_sales_funnel_counts_only_visible_leads(): void
    {
        $rep = User::factory()->sales()->create();
        Lead::factory()->ownedBy($rep)->create(['source' => LeadSource::Telegram]);
        Lead::factory()->ownedBy($rep)->create(['source' => LeadSource::Telegram, 'status' => LeadStatus::Converted, 'qualified_at' => now(), 'converted_at' => now()]);
        Lead::factory()->create(['source' => LeadSource::Telegram]);

        $rows = iterator_to_array(app(ReportRegistry::class)->find('sales_funnel')->rows($rep, ['from' => null, 'to' => null]), false);
        $this->assertCount(1, $rows);
        $this->assertSame([2, 1, 1, 50.0], [$rows[0]['leads'], $rows[0]['converted'], $rows[0]['qualified'], $rows[0]['conversion']]);

        Livewire::actingAs($rep)->test(Show::class, ['report' => 'sales_funnel'])->assertSee(LeadSource::Telegram->label())->assertSee('50 %');
    }

    public function test_csv_and_xlsx_exports(): void
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($rep)->create(['name' => '=HYPERLINK("x")', 'currency' => 'UZS']);
        $order = $this->simpleOrder($rep, '2', '100', $customer);
        app(ConfirmOrder::class)->handle($order, $rep, true);

        $rows = iterator_to_array(app(ReportExporter::class)->rows(app(ReportRegistry::class)->find('orders_backlog'), $rep, ['from' => null, 'to' => null]), false);
        $this->assertContains(__('reports.columns.total').' · '.__('reports.columns.currency'), $rows[0]);
        $this->assertSame(2240.0, $rows[1][4], 'Money as a number in major units.');
        $this->assertSame('UZS', $rows[1][5]);

        $csv = $this->actingAs($rep)->get(route('reports.export', ['report' => 'orders_backlog', 'format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'Formula injection is neutralised.');
        $this->assertStringContainsString($order->number, $csv);

        $xlsx = $this->actingAs($rep)->get(route('reports.export', ['report' => 'orders_backlog', 'format' => 'xlsx']))->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());
        $this->assertTrue(ActivityLog::query()->where('event', 'report_exported')->exists());
    }
}
