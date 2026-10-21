<div class="space-y-6" data-reference-lists>
    <p class="text-sm text-muted">{{ __('reference.intro') }}</p>
    <x-flash />

    <x-tabs :tabs="collect($lists)->mapWithKeys(fn ($key) => [$key => __('reference.lists.'.$key)])->all()" :active="$list" property="list" prefix="ref" :label="__('reference.title')">
        <div class="card">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm" data-reference-table>
                    <thead class="bg-sidebar text-left text-xs text-muted uppercase">
                        <tr>
                            <th scope="col" class="px-3 py-2">{{ __('reference.fields.code') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('reference.fields.name_uz') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('reference.fields.name_ru') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('reference.fields.name_en') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('reference.fields.sort') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('common.status') }}</th>
                            <th scope="col" class="px-3 py-2"><span class="sr-only">{{ __('common.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($this->items as $item)
                            <tr wire:key="ri-{{ $item->id }}" data-reference-item="{{ $item->code }}">
                                <td class="px-3 py-2 font-mono text-xs text-muted">{{ $item->code }}
                                    @if (in_array($item->source, ['user', 'legacy'], true))<x-badge tone="outline">{{ __('reference.sources.'.$item->source) }}</x-badge>@endif
                                </td>
                                @foreach (['uz', 'ru', 'en'] as $lang)
                                    <td class="px-3 py-2">
                                        <label for="ri-{{ $item->id }}-{{ $lang }}" class="sr-only">{{ __('reference.fields.name_'.$lang) }}</label>
                                        <input wire:model="rows.{{ $item->id }}.{{ $lang }}" id="ri-{{ $item->id }}-{{ $lang }}" type="text" maxlength="120" class="form-input" lang="{{ $lang }}">
                                        @error('rows.'.$item->id.'.'.$lang) <p class="form-error">{{ $message }}</p> @enderror
                                    </td>
                                @endforeach
                                <td class="px-3 py-2">
                                    <label for="ri-{{ $item->id }}-sort" class="sr-only">{{ __('reference.fields.sort') }}</label>
                                    <input wire:model="rows.{{ $item->id }}.sort_order" id="ri-{{ $item->id }}-sort" type="number" min="0" class="form-input w-24">
                                </td>
                                <td class="px-3 py-2"><x-badge tone="outline">{{ $item->is_active ? __('common.active') : __('common.inactive') }}</x-badge></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <button type="button" wire:click="saveRow({{ $item->id }})" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                                    <button type="button" wire:click="toggle({{ $item->id }})" class="btn btn-secondary btn-sm" data-action="toggle-reference">{{ $item->is_active ? __('reference.deactivate') : __('reference.activate') }}</button>
                                    <button type="button" wire:click="delete({{ $item->id }})" wire:confirm="{{ __('reference.confirm_delete') }}" class="btn btn-danger-outline btn-sm" data-action="delete-reference">{{ __('common.remove') }}</button>
                                    @error('row-'.$item->id) <p class="form-error text-left" role="alert">{{ $message }}</p> @enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <form wire:submit="add" class="grid gap-3 border-t border-line p-4 sm:grid-cols-6" data-reference-add novalidate>
                <h3 class="font-semibold text-ink sm:col-span-6">{{ __('reference.add') }}</h3>
                <div>
                    <label for="rn-code" class="form-label">{{ __('reference.fields.code') }}</label>
                    <input wire:model="new.code" id="rn-code" type="text" maxlength="60" class="form-input font-mono" aria-describedby="rn-code-hint">
                    <p id="rn-code-hint" class="mt-1 text-xs text-muted">{{ $list === 'currency' ? __('reference.hints.currency_code') : __('reference.hints.code') }}</p>
                    @error('new.code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                @foreach (['uz', 'ru', 'en'] as $lang)
                    <div>
                        <label for="rn-{{ $lang }}" class="form-label">{{ __('reference.fields.name_'.$lang) }}</label>
                        <input wire:model="new.{{ $lang }}" id="rn-{{ $lang }}" type="text" maxlength="120" class="form-input" lang="{{ $lang }}">
                        @error('new.'.$lang) <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <div>
                    <label for="rn-sort" class="form-label">{{ __('reference.fields.sort') }}</label>
                    <input wire:model="new.sort_order" id="rn-sort" type="number" min="0" class="form-input">
                </div>
                <div class="flex items-end"><button type="submit" class="btn btn-primary">{{ __('common.add') }}</button></div>
            </form>
        </div>
    </x-tabs>
</div>
