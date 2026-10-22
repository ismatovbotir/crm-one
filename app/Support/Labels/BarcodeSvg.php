<?php

namespace App\Support\Labels;

/**
 * Renders a barcode as a stretchable inline SVG (bars only; the caption is HTML so it uses the
 * template's font). Symbology "auto" picks EAN-13 for valid 12/13-digit values, else Code 128.
 */
final class BarcodeSvg
{
    /** Quiet zone, in modules, kept on each side of the bars. */
    private const QUIET = 6;

    /**
     * The symbology that will really be used, or null when the value cannot be encoded.
     */
    public static function resolve(string $symbology, string $value): ?string
    {
        $code128 = Code128::canEncode($value) ? 'code128' : null;

        if ($symbology === 'code128') {
            return $code128;
        }

        return Ean13::canEncode($value) ? 'ean13' : $code128;
    }

    /**
     * Human-readable caption for the resolved symbology.
     */
    public static function caption(string $symbology, string $value): string
    {
        return $symbology === 'ean13' ? Ean13::normalize($value) : $value;
    }

    public static function render(string $symbology, string $value): ?string
    {
        $resolved = self::resolve($symbology, $value);

        if ($resolved === null) {
            return null;
        }

        $modules = $resolved === 'ean13' ? Ean13::modules($value) : Code128::modules($value);
        $total = strlen($modules) + 2 * self::QUIET;
        $rects = '';

        preg_match_all('/1+/', $modules, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$run, $start]) {
            $rects .= '<rect x="'.($start + self::QUIET).'" y="0" width="'.strlen($run).'" height="1"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' 1" preserveAspectRatio="none" shape-rendering="crispEdges" fill="#000" class="block size-full" aria-hidden="true">'.$rects.'</svg>';
    }
}
