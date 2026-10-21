<?php

namespace App\Livewire\Settings;

use App\Actions\Agreements\ManageAgreementTemplates;
use App\Enums\Locale;
use App\Enums\TemplateStatus;
use App\Models\AgreementTemplate;
use App\Models\AgreementTemplateVersion;
use App\Services\Agreements\AgreementVariables;
use App\Services\Agreements\Exceptions\AgreementActionException;
use App\Services\Agreements\TemplateRenderer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Edit one template's text per language (Spec 06 7.8, DEC-10): the draft body (HTML subset with
 * `{{ group.key }}` placeholders and `{{#clause:code}}…{{/clause}}` blocks), its optional clauses,
 * live lint and preview with sample data, publish. Published versions are read-only.
 */
#[Layout('layouts.app')]
class AgreementTemplateEditor extends Component
{
    #[Locked]
    public int $templateId;

    #[Url(except: '')]
    public string $locale = '';

    public string $body = '';

    /** @var list<array{code: string, label: string, default: bool}> */
    public array $clauses = [];

    public bool $isActive = true;

    public function mount(AgreementTemplate $template): void
    {
        $this->authorize('manage', AgreementTemplate::class);
        $this->templateId = $template->id;
        $this->locale = Locale::fromAvailable($this->locale)?->value ?? Locale::default()->value;
        $this->isActive = $template->is_active;
        $this->loadLocale();
    }

    #[Computed]
    public function template(): AgreementTemplate
    {
        return AgreementTemplate::query()->with('versions')->findOrFail($this->templateId);
    }

    #[Computed]
    public function published(): ?AgreementTemplateVersion
    {
        return $this->template->versions->where('locale', $this->locale)->where('status', TemplateStatus::Published)->sortByDesc('version')->first();
    }

    #[Computed]
    public function draft(): ?AgreementTemplateVersion
    {
        return $this->template->versions->where('locale', $this->locale)->where('status', TemplateStatus::Draft)->first();
    }

    /**
     * @return list<array{key: string, params: array<string, string>}>
     */
    #[Computed]
    public function lint(): array
    {
        return app(TemplateRenderer::class)->lint($this->body, array_column($this->clauses, 'code'));
    }

    public function switchLocale(string $locale): void
    {
        $this->locale = Locale::fromAvailable($locale)?->value ?? $this->locale;
        $this->resetValidation();
        $this->loadLocale();
    }

    public function addClause(): void
    {
        $this->clauses[] = ['code' => '', 'label' => '', 'default' => false];
    }

    public function removeClause(int $index): void
    {
        unset($this->clauses[$index]);
        $this->clauses = array_values($this->clauses);
    }

    public function saveDraft(ManageAgreementTemplates $manage): void
    {
        $this->authorize('manage', AgreementTemplate::class);
        $this->validateDraft();
        $manage->saveDraft($this->template, $this->locale, $this->body, $this->normalizedClauses());
        $this->refresh();
        session()->flash('status', __('agreements.templates.draft_saved'));
    }

    public function publish(ManageAgreementTemplates $manage): void
    {
        $this->authorize('manage', AgreementTemplate::class);
        $this->validateDraft();
        $draft = $manage->saveDraft($this->template, $this->locale, $this->body, $this->normalizedClauses());

        try {
            $manage->publish($draft, auth()->user());
        } catch (AgreementActionException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->refresh();

            return;
        }

        $this->refresh();
        $this->loadLocale();
        session()->flash('status', __('agreements.templates.published'));
    }

    public function discardDraft(ManageAgreementTemplates $manage): void
    {
        $this->authorize('manage', AgreementTemplate::class);

        if ($this->draft !== null) {
            $manage->discardDraft($this->draft);
        }

        $this->refresh();
        $this->loadLocale();
    }

    public function toggleActive(): void
    {
        $this->authorize('manage', AgreementTemplate::class);
        $this->template->update(['is_active' => ! $this->template->is_active]);
        $this->isActive = ! $this->isActive;
        $this->refresh();
    }

    protected function validateDraft(): void
    {
        $this->validate([
            'body' => ['required', 'string', 'max:200000'],
            'clauses.*.code' => ['required', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'clauses.*.label' => ['required', 'string', 'max:255'],
        ], ['clauses.*.code.regex' => __('agreements.templates.clause_code_invalid')], [
            'body' => __('agreements.templates.body'),
            'clauses.*.code' => __('agreements.templates.clause_code'),
            'clauses.*.label' => __('agreements.templates.clause_label'),
        ]);
    }

    /**
     * @return list<array{code: string, label: string, default: bool}>
     */
    protected function normalizedClauses(): array
    {
        return array_values(array_map(fn (array $clause): array => ['code' => trim($clause['code']), 'label' => trim($clause['label']), 'default' => (bool) ($clause['default'] ?? false)], $this->clauses));
    }

    protected function loadLocale(): void
    {
        $source = $this->draft ?? $this->published;
        $this->body = (string) ($source?->body ?? '');
        $this->clauses = array_values(array_map(fn (array $clause): array => ['code' => (string) $clause['code'], 'label' => (string) $clause['label'], 'default' => (bool) ($clause['default'] ?? false)], $source?->clauses ?? []));
    }

    protected function refresh(): void
    {
        unset($this->template, $this->published, $this->draft, $this->lint);
    }

    public function render(): View
    {
        return view('livewire.settings.agreement-template-editor', [
            'locales' => Locale::cases(),
            'variableKeys' => AgreementVariables::KEYS,
        ])->title($this->template->name);
    }
}
