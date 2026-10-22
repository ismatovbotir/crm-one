@props(['element', 'values', 'logo' => null])
{{--
    One positioned element of a label template, in millimetres. Shared by the designer preview
    (re-rendered through Livewire when a property changes) and the print sheet, so both look alike.
    Price: thousand groups / decimals / currency can be set smaller and aligned top, baseline or bottom.
--}}
@php
    $type = $element['type'];
    $flexH = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$element['align'] ?? 'left'] ?? 'flex-start';
    $flexV = ['top' => 'flex-start', 'middle' => 'center', 'bottom' => 'flex-end'][$element['valign'] ?? 'top'] ?? 'flex-start';
    $box = 'position:absolute;left:'.$element['x'].'mm;top:'.$element['y'].'mm;width:'.$element['w'].'mm;height:'.$element['h'].'mm;overflow:hidden;box-sizing:border-box;';

    if (in_array($type, ['text', 'price', 'barcode'], true)) {
        $box .= 'display:flex;justify-content:'.$flexH.';align-items:'.$flexV.';text-align:'.($element['align'] ?? 'left').';'
            .'font-size:'.$element['font_size'].'pt;line-height:1.1;color:#000;'
            .'font-weight:'.(($element['bold'] ?? false) ? '700' : '400').';font-style:'.(($element['italic'] ?? false) ? 'italic' : 'normal').';';
    }

    $text = '';

    if ($type === 'text') {
        $text = ($element['field'] ?? 'name') === 'custom' ? (string) ($element['text'] ?? '') : (string) ($values['fields'][$element['field']] ?? '');
        $text = ($element['uppercase'] ?? false) ? mb_strtoupper($text) : $text;
    }

    $price = $type === 'price' ? $values['price'] : null;
    $priceScale = ($element['small_scale'] ?? 55).'%';
    $smallAlign = ['top' => 'text-top', 'baseline' => 'baseline', 'bottom' => 'text-bottom'][$element['small_valign'] ?? 'top'] ?? 'text-top';
    $smallStyle = 'font-size:'.$priceScale.';vertical-align:'.$smallAlign.';';

    $barcodeValue = $type === 'barcode' ? (($element['field'] ?? 'barcode') === 'custom' ? (string) ($element['text'] ?? '') : (string) ($values['fields'][$element['field']] ?? '')) : '';
    $symbology = $type === 'barcode' ? \App\Support\Labels\BarcodeSvg::resolve($element['symbology'] ?? 'auto', $barcodeValue) : null;
@endphp
<div class="lbl-el" data-el-id="{{ $element['id'] }}" data-el-type="{{ $type }}" style="{{ $box }}{{ $type === 'line' ? 'background:#000;' : '' }}{{ $type === 'box' ? 'border:'.$element['stroke'].'mm solid #000;' : '' }}">
    @if ($type === 'text')
        <span style="max-width:100%;overflow-wrap:anywhere">{{ $text }}</span>
    @elseif ($type === 'price')
        @if ($price !== null)
            @php
                $groups = $price['groups'];
                $showDecimals = $price['decimals'] !== null && (($element['always_decimals'] ?? false) || trim($price['decimals'], '0') !== '');
                $nbsp = "\u{00A0}";
                $part = fn (string $content, bool $small): string => $small ? '<span style="'.$smallStyle.'">'.e($content).'</span>' : e($content);
                $html = e($groups[0]);

                if (count($groups) > 1) {
                    $html .= $part($nbsp.implode($nbsp, array_slice($groups, 1)), (bool) ($element['small_thousands'] ?? false));
                }

                if ($showDecimals) {
                    $smallDecimals = (bool) ($element['small_decimals'] ?? false);
                    $html .= $part(($smallDecimals ? '' : $price['separator']).$price['decimals'], $smallDecimals);
                }

                if ($element['show_currency'] ?? true) {
                    $html .= $part($nbsp.$price['currency'], (bool) ($element['small_currency'] ?? false));
                }
            @endphp
            <span style="white-space:nowrap">{{ new \Illuminate\Support\HtmlString($html) }}</span>
        @endif
    @elseif ($type === 'barcode')
        @if ($symbology !== null)
            <div style="display:flex;flex-direction:column;width:100%;height:100%;align-items:stretch">
                <div style="flex:1 1 0;min-height:0">{{ new \Illuminate\Support\HtmlString(\App\Support\Labels\BarcodeSvg::render($symbology, $barcodeValue)) }}</div>
                @if ($element['show_text'] ?? true)
                    <div style="text-align:center;white-space:nowrap;overflow:hidden;flex:none">{{ \App\Support\Labels\BarcodeSvg::caption($symbology, $barcodeValue) }}</div>
                @endif
            </div>
        @endif
    @elseif ($type === 'image' && $logo)
        <img src="{{ $logo }}" alt="" style="width:100%;height:100%;object-fit:contain">
    @endif
</div>
