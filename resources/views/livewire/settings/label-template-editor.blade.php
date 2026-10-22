@php
    $palette = [
        'data' => [
            ['label' => __('labels.fields.name'), 'def' => ['type' => 'text', 'field' => 'name', 'w' => 40, 'h' => 8, 'font_size' => 9, 'bold' => true]],
            ['label' => __('labels.fields.sku'), 'def' => ['type' => 'text', 'field' => 'sku', 'w' => 25, 'h' => 4, 'font_size' => 7]],
            ['label' => __('labels.fields.article'), 'def' => ['type' => 'text', 'field' => 'article', 'w' => 25, 'h' => 4, 'font_size' => 7]],
            ['label' => __('labels.fields.serial'), 'def' => ['type' => 'text', 'field' => 'serial', 'w' => 30, 'h' => 4, 'font_size' => 7]],
            ['label' => __('labels.fields.category'), 'def' => ['type' => 'text', 'field' => 'category', 'w' => 30, 'h' => 4, 'font_size' => 7]],
            ['label' => __('labels.fields.unit'), 'def' => ['type' => 'text', 'field' => 'unit', 'w' => 10, 'h' => 4, 'font_size' => 7]],
            ['label' => __('labels.fields.date'), 'def' => ['type' => 'text', 'field' => 'date', 'w' => 18, 'h' => 4, 'font_size' => 7]],
            ['label' => __('labels.fields.barcode'), 'def' => ['type' => 'text', 'field' => 'barcode', 'w' => 30, 'h' => 4, 'font_size' => 7]],
            ['label' => __('labels.fields.custom'), 'def' => ['type' => 'text', 'field' => 'custom', 'text' => __('labels.editor.custom_default'), 'w' => 30, 'h' => 5, 'font_size' => 8]],
        ],
        'special' => [
            ['label' => __('labels.fields.price'), 'def' => ['type' => 'price', 'w' => 40, 'h' => 12, 'font_size' => 20, 'bold' => true, 'align' => 'right', 'valign' => 'bottom', 'small_last_digits' => true, 'small_currency' => true, 'small_scale' => 55, 'small_valign' => 'top']],
            ['label' => __('labels.fields.barcode_bars'), 'def' => ['type' => 'barcode', 'field' => 'barcode', 'w' => 40, 'h' => 12, 'font_size' => 6, 'show_text' => true]],
            ['label' => __('labels.fields.logo'), 'def' => ['type' => 'image', 'w' => 12, 'h' => 12]],
            ['label' => __('labels.fields.line'), 'def' => ['type' => 'line', 'w' => 40, 'h' => 0.3]],
            ['label' => __('labels.fields.box'), 'def' => ['type' => 'box', 'w' => 40, 'h' => 20, 'stroke' => 0.3]],
        ],
    ];
    $fieldOptions = ['name', 'sku', 'article', 'barcode', 'serial', 'category', 'unit', 'date', 'custom'];
@endphp
<div class="space-y-4">
    <a href="{{ route('settings.label-templates') }}" wire:navigate class="link text-sm">&larr; {{ __('labels.editor.back') }}</a>

    <div wire:ignore
         x-data="labelDesigner"
         data-elements="{{ json_encode($template->elements) }}"
         data-width="{{ $template->width_mm }}"
         data-height="{{ $template->height_mm }}"
         data-msg-saved="{{ __('labels.editor.saved') }}"
         data-msg-invalid="{{ __('labels.editor.invalid_size') }}"
         class="space-y-4">

        <header class="card flex flex-wrap items-end gap-4 p-4">
            <div class="min-w-48 flex-1">
                <label for="ld-name" class="form-label">{{ __('labels.templates.name') }}</label>
                <input id="ld-name" type="text" maxlength="100" value="{{ $template->name }}" data-ref="name" class="form-input">
            </div>
            <div class="w-28">
                <label for="ld-width" class="form-label">{{ __('labels.templates.width') }} (mm)</label>
                <input id="ld-width" type="number" min="15" max="300" step="0.5" value="{{ $template->width_mm }}" data-ref="width" class="form-input">
            </div>
            <div class="w-28">
                <label for="ld-height" class="form-label">{{ __('labels.templates.height') }} (mm)</label>
                <input id="ld-height" type="number" min="10" max="300" step="0.5" value="{{ $template->height_mm }}" data-ref="height" class="form-input">
            </div>
            <button type="button" data-ref="save" class="btn btn-primary">{{ __('common.save') }}</button>
            <p data-ref="status" role="status" class="basis-full text-sm text-muted sm:basis-auto"></p>
        </header>

        <div class="grid gap-4 lg:grid-cols-[13rem_1fr_17rem]">
            {{-- Palette: drag a field onto the label, or click it to add at the top-left corner. --}}
            <aside class="card space-y-4 p-3" aria-label="{{ __('labels.editor.palette') }}">
                <p class="text-xs text-muted">{{ __('labels.editor.palette_hint') }}</p>
                @foreach ($palette as $group => $entries)
                    <div>
                        <p class="mb-1 text-xs font-semibold tracking-wide text-muted uppercase">{{ __('labels.editor.group_'.$group) }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($entries as $entry)
                                <button type="button" draggable="true" data-new="{{ json_encode($entry['def']) }}"
                                        class="cursor-grab rounded-md border border-line bg-surface px-2 py-1 text-xs font-medium text-ink hover:border-brand-600">{{ $entry['label'] }}</button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </aside>

            {{-- Canvas: millimetres rendered at physical size, scaled to fit. Overlaps turn red. --}}
            <section class="card overflow-auto bg-canvas p-4" aria-label="{{ __('labels.editor.canvas') }}">
                <div data-ref="stage" class="relative mx-auto" tabindex="0">
                    <div data-ref="label" class="absolute top-0 left-0 origin-top-left bg-white shadow-md ring-2 ring-brand-600" style="width:{{ $template->width_mm }}mm;height:{{ $template->height_mm }}mm">
                        @foreach ($template->elements as $element)
                            <x-label-element :element="$element" :values="$values" :logo="$logo" />
                        @endforeach
                        <div data-ref="overlays" class="pointer-events-none absolute inset-0"></div>
                        <div data-ref="frame" class="absolute hidden border border-dashed border-brand-600" style="pointer-events:none">
                            <span data-ref="handle" class="absolute -right-1.5 -bottom-1.5 size-3 cursor-se-resize rounded-sm border-2 border-white bg-brand-600" style="pointer-events:auto;touch-action:none"></span>
                        </div>
                    </div>
                </div>
                <p class="mt-3 text-center text-xs text-muted" data-ref="scale"></p>
            </section>

            {{-- Properties of the selected element. --}}
            <aside class="card space-y-3 p-3" aria-label="{{ __('labels.editor.properties') }}">
                <p data-ref="noselection" class="text-sm text-muted">{{ __('labels.editor.select_hint') }}</p>
                <div data-ref="props" class="hidden space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['x' => 'X', 'y' => 'Y', 'w' => __('labels.editor.w'), 'h' => __('labels.editor.h')] as $key => $label)
                            <div>
                                <label for="lp-{{ $key }}" class="text-xs text-muted">{{ $label }} (mm)</label>
                                <input id="lp-{{ $key }}" type="number" step="0.5" data-prop="{{ $key }}" class="form-input">
                            </div>
                        @endforeach
                    </div>

                    <div data-show="text barcode">
                        <label for="lp-field" class="form-label">{{ __('labels.editor.field') }}</label>
                        <select id="lp-field" data-prop="field" class="form-input">
                            @foreach ($fieldOptions as $field)
                                <option value="{{ $field }}">{{ __('labels.fields.'.$field) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div data-show="text barcode" data-when-field="custom">
                        <label for="lp-text" class="form-label">{{ __('labels.editor.text') }}</label>
                        <input id="lp-text" type="text" maxlength="200" data-prop="text" class="form-input">
                    </div>

                    <div data-show="text price barcode" class="space-y-3">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="lp-font" class="text-xs text-muted">{{ __('labels.editor.font_size') }} (pt)</label>
                                <input id="lp-font" type="number" min="3" max="120" step="0.5" data-prop="font_size" class="form-input">
                            </div>
                            <div class="flex items-end gap-3 pb-2">
                                <label class="flex items-center gap-1 text-sm font-bold"><input type="checkbox" data-prop="bold"> B</label>
                                <label class="flex items-center gap-1 text-sm italic"><input type="checkbox" data-prop="italic"> I</label>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="lp-align" class="text-xs text-muted">{{ __('labels.editor.align') }}</label>
                                <select id="lp-align" data-prop="align" class="form-input">
                                    @foreach (['left', 'center', 'right'] as $value)
                                        <option value="{{ $value }}">{{ __('labels.editor.align_'.$value) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="lp-valign" class="text-xs text-muted">{{ __('labels.editor.valign') }}</label>
                                <select id="lp-valign" data-prop="valign" class="form-input">
                                    @foreach (['top', 'middle', 'bottom'] as $value)
                                        <option value="{{ $value }}">{{ __('labels.editor.valign_'.$value) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <label data-show="text" class="flex items-center gap-2 text-sm"><input type="checkbox" data-prop="uppercase"> {{ __('labels.editor.uppercase') }}</label>

                    <fieldset data-show="price" class="space-y-2 rounded-md border border-line p-2">
                        <legend class="px-1 text-xs font-semibold text-muted">{{ __('labels.editor.price_format') }}</legend>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" data-prop="small_last_digits"> {{ __('labels.editor.small_last_digits') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" data-prop="show_currency"> {{ __('labels.editor.show_currency') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" data-prop="small_currency"> {{ __('labels.editor.small_currency') }}</label>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="lp-scale" class="text-xs text-muted">{{ __('labels.editor.small_scale') }} (%)</label>
                                <input id="lp-scale" type="number" min="20" max="100" step="5" data-prop="small_scale" class="form-input">
                            </div>
                            <div>
                                <label for="lp-svalign" class="text-xs text-muted">{{ __('labels.editor.small_valign') }}</label>
                                <select id="lp-svalign" data-prop="small_valign" class="form-input">
                                    @foreach (['top', 'baseline', 'bottom'] as $value)
                                        <option value="{{ $value }}">{{ __('labels.editor.small_valign_'.$value) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <div data-show="barcode" class="space-y-2">
                        <div>
                            <label for="lp-sym" class="text-xs text-muted">{{ __('labels.editor.symbology') }}</label>
                            <select id="lp-sym" data-prop="symbology" class="form-input">
                                <option value="auto">{{ __('labels.editor.symbology_auto') }}</option>
                                <option value="code128">Code 128</option>
                                <option value="ean13">EAN-13</option>
                            </select>
                        </div>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" data-prop="show_text"> {{ __('labels.editor.show_text') }}</label>
                    </div>

                    <div data-show="box">
                        <label for="lp-stroke" class="text-xs text-muted">{{ __('labels.editor.stroke') }} (mm)</label>
                        <input id="lp-stroke" type="number" min="0.1" max="3" step="0.1" data-prop="stroke" class="form-input">
                    </div>

                    <div class="flex gap-2 border-t border-line pt-3">
                        <button type="button" data-ref="duplicate" class="btn btn-secondary btn-sm">{{ __('labels.editor.duplicate') }}</button>
                        <button type="button" data-ref="remove" class="btn btn-danger-outline btn-sm">{{ __('labels.editor.remove') }}</button>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>
