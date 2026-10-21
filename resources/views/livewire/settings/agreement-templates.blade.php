<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('agreements.templates.intro') }}</p>
        <button type="button" wire:click="$toggle('creating')" class="btn btn-primary shrink-0"><x-icon name="plus" class="size-4" /> {{ __('agreements.templates.new') }}</button>
    </div>

    <x-flash />

    @if ($creating)
        <form wire:submit="create" class="card grid gap-4 p-5 sm:grid-cols-3" novalidate>
            <div>
                <label for="t-code" class="form-label">{{ __('agreements.templates.code') }}</label>
                <input wire:model="code" id="t-code" type="text" class="form-input font-mono" placeholder="order-standard">
                @error('code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="t-name" class="form-label">{{ __('agreements.templates.name') }}</label>
                <input wire:model="name" id="t-name" type="text" class="form-input">
                @error('name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="t-kind" class="form-label">{{ __('agreements.fields.kind') }}</label>
                <select wire:model="kind" id="t-kind" class="form-input">
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex justify-end gap-2 sm:col-span-3">
                <button type="button" wire:click="$set('creating', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('common.save') }}</button>
            </div>
        </form>
    @endif

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('agreements.templates.name') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('agreements.fields.kind') }}</th>
                    @foreach ($locales as $locale)
                        <th scope="col" class="px-4 py-3">{{ $locale->label() }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($this->templates as $template)
                    <tr wire:key="tpl-{{ $template->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('settings.agreement-templates.edit', $template) }}" wire:navigate class="font-medium text-ink hover:text-brand-700 hover:underline">{{ $template->name }}</a>
                            <span class="block font-mono text-xs text-muted">{{ $template->code }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink">{{ $template->kind->label() }}</td>
                        @foreach ($locales as $locale)
                            @php($version = $template->versions->where('locale', $locale->value)->where('status', $published)->sortByDesc('version')->first())
                            @php($draft = $template->versions->where('locale', $locale->value)->where('status', \App\Enums\TemplateStatus::Draft)->first())
                            <td class="px-4 py-3">
                                @if ($version) <x-badge tone="dark" icon="check">v{{ $version->version }}</x-badge> @else <span class="text-xs font-semibold text-brand-700">{{ __('agreements.templates.missing') }}</span> @endif
                                @if ($draft) <x-badge tone="outline">{{ __('agreements.template_status.draft') }}</x-badge> @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-0"><x-empty-state :title="__('agreements.templates.empty')" icon="agreements" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
