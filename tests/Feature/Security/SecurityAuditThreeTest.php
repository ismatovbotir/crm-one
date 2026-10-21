<?php

namespace Tests\Feature\Security;

use App\Livewire\Notifications\Index;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Services\Reports\Report;
use App\Services\Reports\ReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use Tests\TestCase;
use ZipArchive;

/**
 * Third security audit (roadmap step 9) regressions: formula injection in XLSX report exports,
 * backslash open redirect from notifications, CSV injection in the serial error report (covered in
 * SerialRegistrationTest via csvSafe) and the import/setup screens being admin-only (GoLiveTest).
 */
class SecurityAuditThreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_xlsx_rows_never_contain_formulas(): void
    {
        $row = ReportExporter::xlsxRow(['=HYPERLINK("https://evil.example","x")', 42, null, '+998901234567']);

        $this->assertInstanceOf(StringCell::class, $row->cells[0]);
        $this->assertInstanceOf(NumericCell::class, $row->cells[1]);
        $this->assertInstanceOf(StringCell::class, $row->cells[3]);
    }

    public function test_exported_text_stays_text_in_xlsx(): void
    {
        $report = new class extends Report
        {
            public function key(): string
            {
                return 'probe';
            }

            public function allowed(User $user): bool
            {
                return true;
            }

            public function columns(): array
            {
                return [['key' => 'customer', 'type' => 'text']];
            }

            public function rows(User $user, array $filters): iterable
            {
                yield ['customer' => '=cmd|"/c calc"!A1'];
            }
        };

        ob_start();
        app(ReportExporter::class)->download($report, User::factory()->finance()->create(), ['from' => null, 'to' => null], 'xlsx')->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, (string) ob_get_clean());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('=cmd|&quot;/c calc&quot;!A1', $sheet);
        $this->assertDoesNotMatchRegularExpression('/<f[ >]/', $sheet, 'No formula elements in the sheet.');
    }

    public function test_notification_links_cannot_redirect_off_site(): void
    {
        $user = User::factory()->manager()->create();

        foreach (['/\\evil.example', '//evil.example', "/offers\r\nLocation: https://evil.example", 'javascript:alert(1)'] as $url) {
            $user->notify(new ApprovalRequested('offer', 'OF-1', 'Sam', $url));
        }

        $user->notify(new ApprovalRequested('offer', 'OF-2', 'Sam', '/offers'));
        $component = Livewire::actingAs($user)->test(Index::class);

        foreach ($user->notifications()->get() as $notification) {
            $expected = $notification->data['url'] === '/offers' ? '/offers' : route('notifications.index');
            $component->call('open', $notification->id)->assertRedirect($expected);
        }
    }
}
