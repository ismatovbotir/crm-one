@php
    $template = $this->template;
    $previewScale = $template ? round(min(3, 340 / ($template->width_mm * 96 / 25.4)), 3) : 1;
@endphp
<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('labels.print.intro') }}</p>

    @if ($this->templates->isEmpty())
        <div class="card p-6 text-sm text-ink">
            {{ __('labels.print.no_templates') }}
            @can('manage', \App\Models\Setting::class)
                <a href="{{ route('settings.label-templates') }}" wire:navigate class="link font-semibold">{{ __('labels.print.create_template') }}</a>
            @endcan
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <div class="space-y-6">
                <section class="card p-4" role="search">
                    <label for="lp-search" class="form-label">{{ __('labels.print.search') }}</label>
                    <input wire:model.live.debounce.250ms="search" wire:keydown.enter="addFirst" id="lp-search" type="search" autocomplete="off" autofocus
                           placeholder="{{ __('labels.print.search_placeholder') }}" class="form-input">
                    <p class="mt-1 text-xs text-muted">{{ __('labels.print.search_hint') }}</p>

                    @if (trim($search) !== '')
                        <ul class="mt-3 divide-y divide-line rounded-md border border-line text-sm" aria-label="{{ __('labels.print.results') }}">
                            @foreach ($this->itemResults as $item)
                                <li wire:key="ir-{{ $item->id }}" class="flex items-center justify-between gap-3 px-3 py-2">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-ink">{{ $item->name }}</p>
                                        <p class="font-mono text-xs text-muted">{{ $item->sku }}@if ($item->barcode) · {{ $item->barcode }}@endif @if ($item->salePrice()) · {{ $item->salePrice()->format() }}@endif</p>
                                    </div>
                                    <button type="button" wire:click="add({{ $item->id }})" class="btn btn-secondary btn-sm shrink-0"><x-icon name="plus" class="size-4" /> {{ __('labels.print.add') }}</button>
                                </li>
                            @endforeach
                            @foreach ($this->serialResults as $serial)
                                <li wire:key="sr-{{ $serial->id }}" class="flex items-center justify-between gap-3 px-3 py-2">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-ink">{{ $serial->item->name }}</p>
                                        <p class="font-mono text-xs text-muted">{{ __('labels.print.serial') }}: {{ $serial->serial_raw }}</p>
                                    </div>
                                    <button type="button" wire:click="add({{ $serial->item_id }}, {{ $serial->id }})" class="btn btn-secondary btn-sm shrink-0"><x-icon name="plus" class="size-4" /> {{ __('labels.print.add') }}</button>
                                </li>
                            @endforeach
                            @if ($this->itemResults->isEmpty() && $this->serialResults->isEmpty())
                                <li class="px-3 py-4 text-center text-muted">{{ __('labels.print.no_results') }}</li>
                            @endif
                        </ul>
                    @endif
                </section>

                <section class="card overflow-x-auto">
                    <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                        <h3 class="font-semibold text-ink">{{ __('labels.print.queue') }} <span class="text-sm font-normal text-muted">({{ trans_choice('labels.print.labels_count', $this->totalLabels, ['count' => $this->totalLabels]) }})</span></h3>
                        @if ($queue !== [])
                            <button type="button" wire:click="clear" class="btn btn-secondary btn-sm">{{ __('labels.print.clear') }}</button>
                        @endif
                    </div>
                    @error('queue') <p class="form-error px-4 pt-2">{{ $message }}</p> @enderror
                    <table class="min-w-full divide-y divide-line text-sm">
                        <tbody class="divide-y divide-line bg-surface">
                            @forelse ($this->rows as $row)
                                <tr wire:key="q-{{ $row['index'] }}-{{ $row['item']->id }}-{{ $row['serial']?->id }}">
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-ink">{{ $row['item']->name }}</p>
                                        <p class="font-mono text-xs text-muted">{{ $row['item']->sku }}@if ($row['serial']) · {{ __('labels.print.serial') }}: {{ $row['serial']->serial_raw }}@endif</p>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-ink">{{ $row['item']->salePrice()?->format() ?? __('labels.print.no_price') }}</td>
                                    <td class="w-28 px-4 py-3">
                                        <label for="copies-{{ $row['index'] }}" class="sr-only">{{ __('labels.print.copies') }}</label>
                                        <input wire:model.live.debounce.400ms="queue.{{ $row['index'] }}.copies" id="copies-{{ $row['index'] }}" type="number" min="1" max="{{ \App\Livewire\Catalog\LabelPrint::MAX_COPIES }}" class="form-input">
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="button" wire:click="remove({{ $row['index'] }})" class="btn btn-danger-outline btn-sm" aria-label="{{ __('labels.print.remove') }}">&times;</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="px-4 py-8 text-center text-muted">{{ __('labels.print.queue_empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>
            </div>

            <aside class="space-y-4">
                <section class="card space-y-4 p-4">
                    <div>
                        <label for="lp-template" class="form-label">{{ __('labels.print.template') }}</label>
                        <select wire:model.live="templateId" id="lp-template" class="form-input">
                            @foreach ($this->templates as $option)
                                <option value="{{ $option->id }}" wire:key="tpl-{{ $option->id }}">{{ $option->name }} ({{ rtrim(rtrim(number_format($option->width_mm, 1, '.', ''), '0'), '.') }}×{{ rtrim(rtrim(number_format($option->height_mm, 1, '.', ''), '0'), '.') }} mm)</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($template)
                        <div>
                            <p class="form-label">{{ __('labels.print.preview') }}</p>
                            <div class="overflow-hidden rounded-md bg-canvas p-3">
                                <div class="relative bg-white shadow ring-1 ring-line" style="width:{{ $template->width_mm * 96 / 25.4 * $previewScale }}px;height:{{ $template->height_mm * 96 / 25.4 * $previewScale }}px">
                                    <div class="absolute top-0 left-0 origin-top-left" style="width:{{ $template->width_mm }}mm;height:{{ $template->height_mm }}mm;transform:scale({{ $previewScale }})" wire:key="preview-{{ $template->id }}-{{ $this->totalLabels }}">
                                        @foreach ($template->elements as $element)
                                            <x-label-element :element="$element" :values="$this->previewValues" :logo="$logo" />
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <button type="button" wire:click="print" class="btn btn-primary w-full" @disabled($queue === [])><x-icon name="printer" class="size-4" /> {{ __('labels.print.print_button') }}</button>
                    <p class="text-xs text-muted">{{ __('labels.print.printer_hint') }}</p>
                </section>
            </aside>
        </div>
    @endif
</div>
