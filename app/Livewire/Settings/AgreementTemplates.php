<?php

namespace App\Livewire\Settings;

use App\Actions\Agreements\ManageAgreementTemplates;
use App\Enums\AgreementKind;
use App\Enums\Locale;
use App\Enums\TemplateStatus;
use App\Models\AgreementTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings > Agreement templates (Spec 06 7.8): templates per kind with the published version of
 * each language; create a template, then edit and publish its texts.
 */
#[Layout('layouts.app')]
class AgreementTemplates extends Component
{
    public bool $creating = false;

    public string $code = '';

    public string $name = '';

    public string $kind = 'order';

    public function mount(): void
    {
        $this->authorize('manage', AgreementTemplate::class);
    }

    /**
     * @return Collection<int, AgreementTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return AgreementTemplate::query()->with('versions')->orderBy('kind')->orderBy('name')->get();
    }

    public function create(ManageAgreementTemplates $manage): void
    {
        $this->authorize('manage', AgreementTemplate::class);
        $this->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', 'unique:agreement_templates,code'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:'.implode(',', array_column(AgreementKind::cases(), 'value'))],
        ], ['code.regex' => __('agreements.templates.code_invalid')], [
            'code' => __('agreements.templates.code'),
            'name' => __('agreements.templates.name'),
            'kind' => __('agreements.fields.kind'),
        ]);

        $template = $manage->create($this->code, $this->name, AgreementKind::from($this->kind), auth()->user());
        session()->flash('status', __('agreements.templates.created'));
        $this->redirectRoute('settings.agreement-templates.edit', $template, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.settings.agreement-templates', [
            'kinds' => AgreementKind::options(),
            'locales' => Locale::cases(),
            'published' => TemplateStatus::Published,
        ])->title(__('agreements.templates.title'));
    }
}
