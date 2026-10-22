<?php

namespace App\Livewire\Settings;

use App\Models\LabelTemplate;
use App\Models\Setting;
use App\Support\Labels\LabelData;
use App\Support\Labels\LabelLayout;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings > Label templates > designer. The canvas is browser-side (drag from the palette, move,
 * resize, snap to 0.5 mm); this component stores the result and renders single elements with the
 * same Blade partial the print sheet uses, so the preview is what comes out of the printer.
 */
#[Layout('layouts.app')]
class LabelTemplateEditor extends Component
{
    public LabelTemplate $template;

    public function mount(LabelTemplate $template): void
    {
        $this->authorize('manage', Setting::class);
        $this->template = $template;
    }

    /**
     * Called by the designer whenever an element is added or a property changes.
     *
     * @param  array<string, mixed>  $element
     * @return array{element: array<string, mixed>, html: string}|null
     */
    public function renderElement(array $element, float $width, float $height): ?array
    {
        $this->authorize('manage', Setting::class);

        $clean = LabelLayout::sanitize([$element], max(1, min($width, 300)), max(1, min($height, 300)))[0] ?? null;

        if ($clean === null) {
            return null;
        }

        return [
            'element' => $clean,
            'html' => view('components.label-element', ['element' => $clean, 'values' => LabelData::sample(), 'logo' => Setting::logoUrl()])->render(),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $elements
     */
    public function save(string $name, float $width, float $height, array $elements): void
    {
        $this->authorize('manage', Setting::class);

        $name = trim($name);
        $valid = $name !== '' && mb_strlen($name) <= 100 && $width >= 15 && $width <= 300 && $height >= 10 && $height <= 300;

        if (! $valid) {
            $this->addError('template.name', __('labels.editor.invalid_size'));
            $this->dispatch('label-designer-saved', ok: false);

            return;
        }

        $this->template->forceFill(['name' => $name, 'width_mm' => $width, 'height_mm' => $height]);
        $this->template->setLayout($elements);
        $this->template->save();

        $this->dispatch('label-designer-saved', ok: true);
    }

    public function render(): View
    {
        return view('livewire.settings.label-template-editor', [
            'values' => LabelData::sample(),
            'logo' => Setting::logoUrl(),
        ])->title($this->template->name);
    }
}
