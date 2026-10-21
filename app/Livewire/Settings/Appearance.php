<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\StoreLogo;
use App\Enums\ThemeColor;
use App\Enums\ThemePreset;
use App\Models\Setting;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class Appearance extends Component
{
    use WithFileUploads;

    /** @var array<string, string> ThemeColor value => hex */
    public array $colors = [];

    /** Pending logo upload (png/jpg/webp by content, max 2 MB; re-encoded on save). */
    public ?TemporaryUploadedFile $logo = null;

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);

        $this->colors = ThemeColor::current();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach (ThemeColor::cases() as $color) {
            $rules['colors.'.$color->value] = ['required', 'string', 'regex:'.ThemeColor::HEX_PATTERN.'i'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['colors.*.regex' => __('settings.appearance.hex_invalid')];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        foreach (ThemeColor::cases() as $color) {
            $attributes['colors.'.$color->value] = $color->label();
        }

        return $attributes;
    }

    /**
     * Colors used for the preview and contrast table; invalid inputs fall back to defaults.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function previewColors(): array
    {
        return ThemeColor::sanitize($this->colors);
    }

    /**
     * @return list<array{label: string, foreground: string, background: string, ratio: float, minimum: float, passes: bool}>
     */
    #[Computed]
    public function contrastChecks(): array
    {
        return ThemeColor::contrastChecks($this->previewColors);
    }

    public function applyPreset(string $preset): void
    {
        $this->authorize('manage', Setting::class);

        $this->colors = ThemePreset::from($preset)->colors();
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize('manage', Setting::class);

        foreach ($this->colors as $key => $value) {
            $this->colors[$key] = strtolower(trim((string) $value));
        }

        $validated = $this->validate()['colors'];

        $failing = array_filter(ThemeColor::contrastChecks($validated), fn (array $check): bool => ! $check['passes']);

        if ($failing !== []) {
            foreach ($failing as $check) {
                $this->addError('contrast', __('settings.appearance.contrast_error', [
                    'label' => $check['label'],
                    'ratio' => number_format($check['ratio'], 2),
                    'minimum' => number_format($check['minimum'], 1),
                ]));
            }

            return;
        }

        $values = [];

        foreach (ThemeColor::cases() as $color) {
            $values[$color->settingKey()] = $validated[$color->value];
        }

        Setting::putMany($values, auth()->user());

        session()->flash('status', __('settings.appearance.saved'));

        // Full reload (no wire:navigate) so the new <style> in <head> is applied.
        $this->redirectRoute('settings.appearance');
    }

    public function resetToDefaults(): void
    {
        $this->authorize('manage', Setting::class);

        Setting::forget(ThemeColor::settingKeys());

        session()->flash('status', __('settings.appearance.reset_done'));

        $this->redirectRoute('settings.appearance');
    }

    public function uploadLogo(StoreLogo $storeLogo): void
    {
        $this->authorize('manage', Setting::class);

        $this->validate([
            'logo' => ['bail', 'required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', $this->rasterImageRule()],
        ], [], ['logo' => __('validation.attributes.logo')]);

        $previous = Setting::get(Setting::LOGO_KEY);
        $path = $storeLogo->handle($this->logo);

        Setting::putMany([Setting::LOGO_KEY => $path], auth()->user());

        if (filled($previous)) {
            Storage::disk('public')->delete($previous);
        }

        $this->logo = null;
        session()->flash('status', __('settings.appearance.logo_updated'));

        $this->redirectRoute('settings.appearance');
    }

    public function resetLogo(): void
    {
        $this->authorize('manage', Setting::class);

        $previous = Setting::get(Setting::LOGO_KEY);

        Setting::forget([Setting::LOGO_KEY]);

        if (filled($previous)) {
            Storage::disk('public')->delete($previous);
        }

        session()->flash('status', __('settings.appearance.logo_reset_done'));

        $this->redirectRoute('settings.appearance');
    }

    /**
     * The `mimes` rule trusts the client-reported type in some paths; this checks the actual
     * content (finfo + getimagesize) so a spoofed SVG/PHP named logo.png is refused.
     */
    protected function rasterImageRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile || ! StoreLogo::isAcceptableImage($value)) {
                $fail(__('settings.appearance.logo_not_image'));
            }
        };
    }

    public function render(): View
    {
        return view('livewire.settings.appearance', [
            'themeColors' => ThemeColor::cases(),
            'presets' => ThemePreset::cases(),
            'logoUrl' => Setting::logoUrl(),
            'hasCustomLogo' => Setting::hasCustomLogo(),
        ])->title(__('settings.appearance.title'));
    }
}
