<?php

namespace App\Livewire\Settings;

use App\Models\LabelTemplate;
use App\Models\Setting;
use App\Support\Labels\LabelLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings > Label templates: the list of price-tag layouts; a new one starts from a starter layout
 * and is then arranged in the designer.
 */
#[Layout('layouts.app')]
class LabelTemplates extends Component
{
    public bool $creating = false;

    public string $name = '';

    public string $width = '58';

    public string $height = '40';

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);
    }

    /**
     * @return Collection<int, LabelTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return LabelTemplate::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->authorize('manage', Setting::class);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'width' => ['required', 'numeric', 'min:15', 'max:300'],
            'height' => ['required', 'numeric', 'min:10', 'max:300'],
        ], [], [
            'name' => __('labels.templates.name'),
            'width' => __('labels.templates.width'),
            'height' => __('labels.templates.height'),
        ]);

        $width = (float) $validated['width'];
        $height = (float) $validated['height'];
        $template = LabelTemplate::query()->create([
            'name' => $validated['name'],
            'width_mm' => $width,
            'height_mm' => $height,
            'elements' => LabelLayout::starter($width, $height),
            'is_default' => ! LabelTemplate::query()->exists(),
            'created_by' => auth()->id(),
        ]);

        $this->redirectRoute('settings.label-templates.edit', $template, navigate: true);
    }

    public function makeDefault(int $id): void
    {
        $this->authorize('manage', Setting::class);
        LabelTemplate::query()->findOrFail($id)->markAsDefault();
        unset($this->templates);
    }

    public function duplicate(int $id): void
    {
        $this->authorize('manage', Setting::class);
        $source = LabelTemplate::query()->findOrFail($id);
        $copy = $source->replicate(['is_default']);
        $copy->forceFill(['name' => mb_substr($source->name, 0, 90).' ('.__('labels.templates.copy').')', 'is_default' => false, 'created_by' => auth()->id()])->save();
        unset($this->templates);
        session()->flash('status', __('labels.templates.duplicated'));
    }

    public function delete(int $id): void
    {
        $this->authorize('manage', Setting::class);
        $template = LabelTemplate::query()->findOrFail($id);
        $wasDefault = $template->is_default;
        $template->delete();

        if ($wasDefault) {
            LabelTemplate::query()->orderBy('name')->first()?->markAsDefault();
        }

        unset($this->templates);
        session()->flash('status', __('labels.templates.deleted'));
    }

    public function render(): View
    {
        return view('livewire.settings.label-templates')->title(__('labels.templates.title'));
    }
}
