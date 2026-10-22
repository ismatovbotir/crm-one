<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('labels.templates.intro') }}</p>
        <button type="button" wire:click="$toggle('creating')" class="btn btn-primary shrink-0"><x-icon name="plus" class="size-4" /> {{ __('labels.templates.new') }}</button>
    </div>

    <x-flash />

    @if ($creating)
        <form wire:submit="create" class="card grid gap-4 p-5 sm:grid-cols-3" novalidate>
            <div>
                <label for="lt-name" class="form-label">{{ __('labels.templates.name') }}</label>
                <input wire:model="name" id="lt-name" type="text" class="form-input">
                @error('name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="lt-width" class="form-label">{{ __('labels.templates.width') }} (mm)</label>
                <input wire:model="width" id="lt-width" type="number" step="0.5" inputmode="decimal" class="form-input">
                @error('width') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="lt-height" class="form-label">{{ __('labels.templates.height') }} (mm)</label>
                <input wire:model="height" id="lt-height" type="number" step="0.5" inputmode="decimal" class="form-input">
                @error('height') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 sm:col-span-3">
                <button type="button" wire:click="$set('creating', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('labels.templates.create_and_design') }}</button>
            </div>
        </form>
    @endif

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('labels.templates.name') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('labels.templates.size') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('labels.templates.elements') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('common.actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($this->templates as $template)
                    <tr wire:key="lt-{{ $template->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('settings.label-templates.edit', $template) }}" wire:navigate class="font-medium text-ink hover:text-brand-700 hover:underline">{{ $template->name }}</a>
                            @if ($template->is_default) <x-badge tone="dark" icon="check">{{ __('labels.templates.default') }}</x-badge> @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-ink">{{ rtrim(rtrim(number_format($template->width_mm, 1, '.', ''), '0'), '.') }} × {{ rtrim(rtrim(number_format($template->height_mm, 1, '.', ''), '0'), '.') }} mm</td>
                        <td class="px-4 py-3 text-muted">{{ count($template->elements) }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap justify-end gap-2">
                                <a href="{{ route('settings.label-templates.edit', $template) }}" wire:navigate class="btn btn-secondary btn-sm">{{ __('labels.templates.design') }}</a>
                                @unless ($template->is_default)
                                    <button type="button" wire:click="makeDefault({{ $template->id }})" class="btn btn-secondary btn-sm">{{ __('labels.templates.make_default') }}</button>
                                @endunless
                                <button type="button" wire:click="duplicate({{ $template->id }})" class="btn btn-secondary btn-sm">{{ __('labels.templates.duplicate') }}</button>
                                <button type="button" wire:click="delete({{ $template->id }})" wire:confirm="{{ __('labels.templates.delete_confirm') }}" class="btn btn-danger-outline btn-sm">{{ __('labels.templates.delete') }}</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-muted">{{ __('labels.templates.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
