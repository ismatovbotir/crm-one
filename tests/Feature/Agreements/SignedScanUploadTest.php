<?php

namespace Tests\Feature\Agreements;

use App\Actions\Agreements\RecordSignature;
use App\Enums\AgreementStatus;
use App\Livewire\Agreements\Show;
use App\Models\Agreement;
use App\Models\Attachment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spec 06 section 13 "File uploads", AC-42: pdf/jpg/png only, type sniffed from the bytes,
 * stored privately, downloadable only by users who may see the agreement.
 */
class SignedScanUploadTest extends TestCase
{
    use RefreshDatabase;

    private Agreement $agreement;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->rep = User::factory()->sales()->create();
        $order = Order::factory()->ownedBy($this->rep)->create();
        $this->agreement = Agreement::factory()->status(AgreementStatus::SentForSignature)->create(['customer_id' => $order->customer_id, 'order_id' => $order->id, 'kind' => 'order']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedFiles(): array
    {
        return [
            'html' => ['scan.html', '<html><script>alert(1)</script></html>'],
            'svg' => ['scan.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'renamed executable' => ['scan.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 64)],
            'html renamed to png' => ['scan.png', '<!DOCTYPE html><html><body>x</body></html>'],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_disallowed_files_are_rejected(string $name, string $content): void
    {
        try {
            app(RecordSignature::class)->upload($this->agreement, $this->rep, UploadedFile::fake()->createWithContent($name, $content));
            $this->fail('The file must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scan', $exception->errors());
        }

        $this->assertSame(0, Attachment::query()->count());
        $this->assertNull($this->agreement->fresh()->customer_signed_uploaded_at);
    }

    public function test_a_valid_pdf_is_stored_privately_and_served_only_to_authorized_users(): void
    {
        Livewire::actingAs($this->rep)->test(Show::class, ['agreement' => $this->agreement])
            ->call('openPanel', 'upload')
            ->set('scan', UploadedFile::fake()->createWithContent('Signed agreement.pdf', "%PDF-1.4\n%%EOF"))
            ->call('uploadScan')
            ->assertHasNoErrors();

        $attachment = Attachment::query()->sole();
        $this->assertSame(['application/pdf', 'local', 'signed_scan'], [$attachment->mime, $attachment->disk, $attachment->purpose]);
        $this->assertStringNotContainsString('Signed agreement', $attachment->path, 'Stored under a random name.');
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame(hash('sha256', "%PDF-1.4\n%%EOF"), $attachment->sha256);

        $this->actingAs($this->rep)->get(route('attachments.download', $attachment))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->sales()->create())->get(route('attachments.download', $attachment))->assertForbidden();
        $this->assertTrue($this->agreement->activities()->where('event', 'scan_downloaded')->exists());
    }
}
