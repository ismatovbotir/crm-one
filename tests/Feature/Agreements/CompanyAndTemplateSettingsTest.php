<?php

namespace Tests\Feature\Agreements;

use App\Enums\Locale;
use App\Enums\TemplateStatus;
use App\Livewire\Customers\Finance;
use App\Livewire\Settings\AgreementTemplateEditor;
use App\Livewire\Settings\AgreementTemplates;
use App\Livewire\Settings\Company;
use App\Models\AgreementTemplate;
use App\Models\CompanyBankAccount;
use App\Models\Customer;
use App\Models\User;
use App\Support\CompanyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Settings > Company and Agreement templates (Spec 06 7.8/7.9), customer Finance tab (4.2).
 */
class CompanyAndTemplateSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_manages_the_company_profile_and_sales_cannot(): void
    {
        $finance = User::factory()->finance()->create();

        Livewire::actingAs($finance)->test(Company::class)
            ->set('profile.legal_name.ru', 'ООО «Тест»')
            ->set('profile.tax_id', '12345')
            ->call('saveProfile')
            ->assertHasErrors('profile.tax_id')
            ->set('profile.tax_id', '305123456')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->call('editBank')
            ->set('bank.bank_name', 'Kapitalbank')
            ->set('bank.mfo', '01158')
            ->set('bank.account_number', '20208000900123456001')
            ->set('bank.is_default', true)
            ->call('saveBank')
            ->assertHasNoErrors()
            ->call('editSignatory')
            ->set('signatory.full_name', 'Bakhtiyor Karimov')
            ->set('signatory.position', 'Director')
            ->set('signatory.is_default', true)
            ->call('saveSignatory')
            ->assertHasNoErrors();

        $this->assertSame('ООО «Тест»', CompanyProfile::get('legal_name', Locale::Ru));
        $this->assertSame('ООО «Тест»', CompanyProfile::get('legal_name', Locale::En), 'Falls back to another language when empty.');
        $this->assertTrue(CompanyBankAccount::defaultFor('UZS')?->is_default);

        $this->actingAs(User::factory()->sales()->create())->get(route('settings.company'))->assertForbidden();
        $this->actingAs($finance)->get(route('dashboard'))->assertSee(__('nav.settings_company'))->assertSee(__('nav.settings_agreement_templates'))->assertDontSee(__('nav.settings_appearance'));
    }

    public function test_templates_are_published_only_when_the_lint_passes_and_stay_immutable(): void
    {
        $finance = User::factory()->finance()->create();

        Livewire::actingAs($finance)->test(AgreementTemplates::class)
            ->set('creating', true)
            ->set('code', 'service-standard')
            ->set('name', 'Service agreement')
            ->set('kind', 'order')
            ->call('create')
            ->assertRedirect();

        $template = AgreementTemplate::query()->where('code', 'service-standard')->sole();

        Livewire::actingAs($finance)->test(AgreementTemplateEditor::class, ['template' => $template])
            ->set('body', '<p>{{ order.number }} {{ customer.secret }}</p><script>x</script>')
            ->call('publish')
            ->assertSee(__('agreements.validation.template_unknown_placeholders', ['keys' => 'customer.secret']))
            ->set('body', '<h1>Agreement {{ agreement.number }}</h1>{{#clause:nda}}<p>NDA</p>{{/clause}}')
            ->call('addClause')
            ->set('clauses.0.code', 'nda')
            ->set('clauses.0.label', 'Confidentiality')
            ->call('publish')
            ->assertHasNoErrors();

        $version = $template->versions()->sole();
        $this->assertSame(TemplateStatus::Published, $version->status);
        $this->assertSame(hash('sha256', $version->body), $version->checksum);

        $this->expectException(LogicException::class);
        $version->update(['body' => '<p>changed</p>']);
    }

    public function test_customer_finance_tab_credit_is_finance_only(): void
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($rep)->create();

        Livewire::actingAs($rep)->test(Finance::class, ['customerId' => $customer->id])
            ->set('details.oked', '47190')
            ->call('saveDetails')
            ->assertHasNoErrors()
            ->call('saveCredit')
            ->assertForbidden();

        $this->assertSame('47190', $customer->fresh()->oked);

        Livewire::actingAs(User::factory()->finance()->create())->test(Finance::class, ['customerId' => $customer->id])
            ->set('credit.credit_limit', '10000000.00')
            ->set('credit.credit_limit_currency', 'UZS')
            ->call('saveCredit')
            ->assertHasNoErrors();

        $this->assertSame(1_000_000_000, $customer->fresh()->credit_limit_minor);
        $this->actingAs($rep)->get(route('customers.show', ['customer' => $customer, 'tab' => 'finance']))->assertOk()->assertSee('data-customer-finance', false);
    }
}
