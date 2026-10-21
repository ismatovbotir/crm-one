<?php

namespace Tests\Feature\Agreements;

use App\Actions\Agreements\GenerateAgreement;
use App\Actions\Agreements\RecordSignature;
use App\Actions\Agreements\ReissueAgreement;
use App\Actions\Agreements\ReviewAgreement;
use App\Actions\Agreements\SendAgreement;
use App\Actions\Agreements\UpdateAgreementDraft;
use App\Actions\Orders\ConfirmOrder;
use App\Actions\Orders\StartPackaging;
use App\Enums\AgreementChannel;
use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\OrderStatus;
use App\Livewire\Agreements\Show;
use App\Models\Agreement;
use App\Models\AgreementVersion;
use App\Models\Order;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 Part B: generation (BR-AG.1/2, AC-12), review (AC-13), sending with stored versions
 * (AC-14 print/courier), signing (DEC-11, AC-15), immutability and re-issue (AC-16).
 */
class AgreementWorkflowTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private User $rep;

    private User $accountant;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpCompany();
        $this->seedAgreementTemplates();
        $this->rep = User::factory()->sales()->create();
        $this->accountant = User::factory()->accountant()->create();
        $this->order = $this->simpleOrder($this->rep, '3', '10', $this->agreementReadyCustomer($this->rep));
        app(ConfirmOrder::class)->handle($this->order, $this->rep);
        $this->order->refresh();
    }

    private function generate(): Agreement
    {
        return app(GenerateAgreement::class)->handle(AgreementKind::Order, $this->order->customer, $this->order, $this->accountant);
    }

    public function test_generation_freezes_variables_and_renders_in_every_language(): void
    {
        $agreement = $this->generate();

        $this->assertSame(AgreementStatus::Draft, $agreement->status);
        $this->assertMatchesRegularExpression('/^AG-\d{4}-\d{5}$/', $agreement->number);
        $this->assertSame($this->order->gross_minor, $agreement->value_minor);
        $this->assertSame($this->order->number, $agreement->variables['order.number']);
        $this->assertSame(['penalty' => true, 'confidentiality' => false], $agreement->clause_options);
        $this->assertFalse($agreement->is_non_standard);

        foreach (['uz' => 'Mahsulot yetkazib berish shartnomasi', 'ru' => 'Договор поставки', 'en' => 'Supply agreement'] as $lang => $title) {
            $this->actingAs($this->accountant)->get(route('agreements.print', ['agreement' => $agreement, 'lang' => $lang]))
                ->assertOk()
                ->assertSee($title)
                ->assertSee('Buyer LLC')
                ->assertSee(__('documents.agreement.draft_mark', [], $lang))
                ->assertDontSee('{{', false);
        }

        $this->expectExceptionObject(new AgreementActionException('already_exists'));
        $this->generate();
    }

    /** AC-12 */
    public function test_missing_customer_data_blocks_sending_with_a_list(): void
    {
        $this->order->customer->bankAccounts()->delete();
        $this->order->customer->forceFill(['signatory_name' => null])->saveQuietly();
        $agreement = $this->generate();

        try {
            app(SendAgreement::class)->handle($agreement, $this->accountant, [AgreementChannel::Print]);
            $this->fail('Sending must be refused.');
        } catch (AgreementActionException $exception) {
            $this->assertSame('invalid', $exception->key);
            $this->assertStringContainsString(__('agreements.variables.customer_signatory_name'), $exception->getMessage());
            $this->assertStringContainsString(__('agreements.variables.customer_bank_account'), $exception->getMessage());
        }

        Livewire::actingAs($this->accountant)->test(Show::class, ['agreement' => $agreement])->assertSee('data-problems', false);
        $this->assertSame(AgreementStatus::Draft, $agreement->fresh()->status);
    }

    /** AC-13 */
    public function test_non_standard_terms_need_review_by_someone_else(): void
    {
        $agreement = $this->generate();
        app(UpdateAgreementDraft::class)->handle($agreement, ['clause_options' => ['penalty' => false, 'confidentiality' => false]]);
        $agreement->refresh();

        $this->assertTrue($agreement->is_non_standard);
        $this->assertSame(['clauses_changed'], $agreement->non_standard_reasons);

        try {
            app(SendAgreement::class)->handle($agreement, $this->accountant, [AgreementChannel::Print]);
            $this->fail('Non-standard terms must be reviewed.');
        } catch (AgreementActionException $exception) {
            $this->assertSame('review_required', $exception->key);
        }

        app(ReviewAgreement::class)->submit($agreement, $this->accountant);
        $this->assertSame(AgreementStatus::InReview, $agreement->fresh()->status);
        $this->assertFalse($this->accountant->can('approve', $agreement->fresh()));

        $finance = User::factory()->finance()->create();
        $this->expectsSelfApprovalToFail($agreement, $this->accountant);

        app(ReviewAgreement::class)->approve($agreement, $finance, 'Penalty waived for this customer');
        app(SendAgreement::class)->handle($agreement->fresh(), $this->accountant, [AgreementChannel::Print]);
        $this->assertSame(AgreementStatus::SentForSignature, $agreement->fresh()->status);
    }

    private function expectsSelfApprovalToFail(Agreement $agreement, User $preparer): void
    {
        try {
            app(ReviewAgreement::class)->approve($agreement, $preparer);
            $this->fail('The preparer may not approve.');
        } catch (AgreementActionException $exception) {
            $this->assertSame('self_approval', $exception->key);
        }
    }

    /** AC-14 (print/courier), AC-15, AC-6 */
    public function test_send_sign_and_activate(): void
    {
        $agreement = $this->generate();

        Livewire::actingAs($this->accountant)->test(Show::class, ['agreement' => $agreement])
            ->call('openPanel', 'send')
            ->set('channels', ['print', 'courier'])
            ->call('send')
            ->assertHasNoErrors();

        $agreement->refresh();
        $this->assertSame(AgreementStatus::SentForSignature, $agreement->status);
        $this->assertSame(2, $agreement->dispatches()->count());
        $stored = AgreementVersion::query()->where('agreement_id', $agreement->id)->get();
        $this->assertSame([$agreement->document_locale], $stored->pluck('locale')->all());
        $this->assertSame(hash('sha256', $stored->first()->html), $stored->first()->sha256);

        $this->actingAs($this->accountant)->get(route('agreements.print', $agreement))
            ->assertOk()
            ->assertSee($stored->first()->sha256)
            ->assertDontSee(__('documents.agreement.draft_mark', [], $agreement->document_locale));

        $this->assertTrue($this->rep->can('uploadScan', $agreement));
        app(RecordSignature::class)->upload($agreement, $this->rep, UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"));
        $agreement->refresh();
        $this->assertNotNull($agreement->customer_signed_uploaded_at);
        $this->assertSame(AgreementStatus::SentForSignature, $agreement->status);
        $this->assertFalse($this->rep->can('confirmSigned', $agreement), 'Sales may upload but never confirm (SoD-6).');

        app(RecordSignature::class)->confirm($agreement, $this->accountant, [
            'signed_on' => now('Asia/Tashkent')->toDateString(),
            'customer_signatory_name' => 'Ali Valiyev',
        ]);
        $agreement->refresh();

        $this->assertSame(AgreementStatus::Active, $agreement->status);
        $this->assertSame($this->accountant->id, $agreement->signed_confirmed_by);
        $this->assertTrue($this->order->fresh()->hasBindingAgreement());

        app(StartPackaging::class)->handle($this->order->fresh(), $this->rep);
        $this->assertSame(OrderStatus::Packing, $this->order->fresh()->status);
    }

    /** AC-16 */
    public function test_sent_text_is_immutable_and_reissue_opens_version_two(): void
    {
        $agreement = $this->generate();
        app(SendAgreement::class)->handle($agreement, $this->accountant, [AgreementChannel::Print]);
        $first = AgreementVersion::query()->where('agreement_id', $agreement->id)->firstOrFail();

        try {
            $first->update(['html' => 'tampered']);
            $this->fail('Stored versions are immutable.');
        } catch (LogicException) {
            $this->assertNotSame('tampered', $first->fresh()->html);
        }

        try {
            app(UpdateAgreementDraft::class)->handle($agreement->fresh(), ['notice_days' => 10]);
            $this->fail('A sent agreement cannot be edited.');
        } catch (AgreementActionException $exception) {
            $this->assertSame('not_editable', $exception->key);
        }

        app(ReissueAgreement::class)->handle($agreement->fresh(), $this->accountant, 'Wrong delivery address');
        $agreement->refresh();
        $this->assertSame([AgreementStatus::Draft, 2], [$agreement->status, $agreement->version]);

        $this->actingAs($this->accountant)->get(route('agreements.print', ['agreement' => $agreement, 'version' => 1]))
            ->assertOk()
            ->assertSee($first->sha256);
    }

    public function test_an_order_agreement_can_be_cancelled_and_prepared_again(): void
    {
        $agreement = $this->generate();

        Livewire::actingAs($this->accountant)->test(Show::class, ['agreement' => $agreement])
            ->call('openPanel', 'cancel')
            ->set('comment', 'Wrong template')
            ->call('cancel');

        $this->assertSame(AgreementStatus::Cancelled, $agreement->fresh()->status);
        $this->assertNotSame($agreement->id, $this->generate()->id);
    }
}
