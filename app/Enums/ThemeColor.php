<?php

namespace App\Enums;

use App\Models\Setting;

/**
 * Adjustable theme colors. Each case maps to a Tailwind theme token declared in
 * resources/css/app.css; saved values are injected as runtime CSS variables
 * (see partials/head) so no asset rebuild is needed.
 */
enum ThemeColor: string
{
    case Primary = 'primary';
    case PrimaryHover = 'primary_hover';
    case Sidebar = 'sidebar';
    case SidebarText = 'sidebar_text';
    case Surface = 'surface';
    case Text = 'text';
    case Border = 'border';

    /** WCAG 2.x AA threshold for normal-size text. */
    public const MIN_CONTRAST = 4.5;

    /** WCAG 2.x AA threshold for non-text UI components (e.g. the active nav pill against the sidebar). */
    public const MIN_NON_TEXT_CONTRAST = 3.0;

    public const WHITE = '#ffffff';

    public const HEX_PATTERN = '/^#[0-9a-f]{6}$/';

    public function label(): string
    {
        return __('settings.colors.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('settings.colors.'.$this->value.'.description');
    }

    public function default(): string
    {
        return match ($this) {
            self::Primary => '#e51932',
            self::PrimaryHover => '#c4142a',
            self::Sidebar => '#d5d8de',
            self::SidebarText => '#1f2937',
            self::Surface => '#ffffff',
            self::Text => '#1f2937',
            self::Border => '#c0c4cc',
        };
    }

    public function cssVariable(): string
    {
        return match ($this) {
            self::Primary => '--color-brand-600',
            self::PrimaryHover => '--color-brand-700',
            self::Sidebar => '--color-sidebar',
            self::SidebarText => '--color-sidebar-text',
            self::Surface => '--color-surface',
            self::Text => '--color-ink',
            self::Border => '--color-line',
        };
    }

    public function settingKey(): string
    {
        return 'theme.'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function settingKeys(): array
    {
        return array_map(fn (self $color): string => $color->settingKey(), self::cases());
    }

    /**
     * @return array<string, string> color value => hex
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::cases() as $color) {
            $defaults[$color->value] = $color->default();
        }

        return $defaults;
    }

    /**
     * Saved colors merged over defaults. Anything that is not a valid hex is ignored,
     * so nothing but #rrggbb can ever reach the inline <style> block.
     *
     * @return array<string, string>
     */
    public static function current(): array
    {
        $settings = Setting::allCached();

        return self::sanitize(array_combine(
            array_map(fn (self $color): string => $color->value, self::cases()),
            array_map(fn (self $color): ?string => $settings[$color->settingKey()] ?? null, self::cases()),
        ));
    }

    /**
     * @param  array<string, mixed>  $colors
     * @return array<string, string>
     */
    public static function sanitize(array $colors): array
    {
        $clean = [];

        foreach (self::cases() as $color) {
            $value = self::normalize($colors[$color->value] ?? null);
            $clean[$color->value] = $value ?? $color->default();
        }

        return $clean;
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match(self::HEX_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * CSS custom properties for the given colors. Base tokens are always emitted;
     * derived shades are only emitted when their source differs from the default,
     * so an untouched theme renders exactly the values in app.css.
     *
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    public static function cssVariables(array $colors): array
    {
        $colors = self::sanitize($colors);
        $changed = fn (self $color): bool => $colors[$color->value] !== $color->default();

        $variables = [];

        foreach (self::cases() as $color) {
            $variables[$color->cssVariable()] = $colors[$color->value];
        }

        $primary = $colors[self::Primary->value];
        $hover = $colors[self::PrimaryHover->value];
        $sidebar = $colors[self::Sidebar->value];
        $sidebarText = $colors[self::SidebarText->value];
        $surface = $colors[self::Surface->value];
        $text = $colors[self::Text->value];
        $border = $colors[self::Border->value];

        if ($changed(self::Primary)) {
            $variables['--color-brand-50'] = self::mix($primary, self::WHITE, 0.06);
            $variables['--color-brand-100'] = self::mix($primary, self::WHITE, 0.12);
            $variables['--color-brand-200'] = self::mix($primary, self::WHITE, 0.25);
            $variables['--color-brand-500'] = self::mix($primary, self::WHITE, 0.85);
        }

        if ($changed(self::PrimaryHover)) {
            $variables['--color-brand-800'] = self::mix($hover, '#000000', 0.8);
        }

        if ($changed(self::Sidebar) || $changed(self::SidebarText)) {
            $variables['--color-sidebar-muted'] = self::softenedText($sidebarText, $sidebar, 0.75);
            $variables['--color-sidebar-line'] = self::mix($sidebar, $sidebarText, 0.88);
        }

        if ($changed(self::Text)) {
            $variables['--color-ink-soft'] = self::mix($text, self::WHITE, 0.85);
        }

        if ($changed(self::Text) || $changed(self::Surface)) {
            $variables['--color-muted'] = self::softenedText($text, $surface, 0.68);
        }

        if ($changed(self::Surface) || $changed(self::Border)) {
            $variables['--color-canvas'] = self::mix($surface, $border, 0.7);
        }

        if ($changed(self::Border) || $changed(self::Text)) {
            $variables['--color-line-strong'] = self::mix($border, $text, 0.8);
        }

        return $variables;
    }

    /**
     * @param  array<string, string>  $colors
     */
    public static function cssDeclarations(array $colors): string
    {
        $declarations = [];

        foreach (self::cssVariables($colors) as $name => $value) {
            $declarations[] = $name.':'.$value;
        }

        return implode(';', $declarations);
    }

    /**
     * Secondary text: the text color softened towards its background, unless that
     * would drop below AA, in which case the text color itself is used.
     */
    public static function softenedText(string $text, string $background, float $weight): string
    {
        $softened = self::mix($text, $background, $weight);

        return self::contrastRatio($softened, $background) >= self::MIN_CONTRAST ? $softened : $text;
    }

    /**
     * Contrast checks for the key foreground/background pairs. Saving is blocked if any fails.
     *
     * @param  array<string, string>  $colors
     * @return list<array{label: string, foreground: string, background: string, ratio: float, minimum: float, passes: bool}>
     */
    public static function contrastChecks(array $colors): array
    {
        $colors = self::sanitize($colors);

        $pairs = [
            [__('settings.contrast.white_on_primary'), self::WHITE, $colors[self::Primary->value], self::MIN_CONTRAST],
            [__('settings.contrast.white_on_primary_hover'), self::WHITE, $colors[self::PrimaryHover->value], self::MIN_CONTRAST],
            [__('settings.contrast.links_on_surface'), $colors[self::PrimaryHover->value], $colors[self::Surface->value], self::MIN_CONTRAST],
            [__('settings.contrast.text_on_surface'), $colors[self::Text->value], $colors[self::Surface->value], self::MIN_CONTRAST],
            [__('settings.contrast.sidebar_text_on_sidebar'), $colors[self::SidebarText->value], $colors[self::Sidebar->value], self::MIN_CONTRAST],
            [__('settings.contrast.nav_pill_on_sidebar'), $colors[self::Primary->value], $colors[self::Sidebar->value], self::MIN_NON_TEXT_CONTRAST],
        ];

        return array_map(function (array $pair): array {
            [$label, $foreground, $background, $minimum] = $pair;
            $ratio = self::contrastRatio($foreground, $background);

            return [
                'label' => $label,
                'foreground' => $foreground,
                'background' => $background,
                'ratio' => floor($ratio * 100) / 100,
                'minimum' => $minimum,
                'passes' => $ratio >= $minimum,
            ];
        }, $pairs);
    }

    /**
     * WCAG 2.x contrast ratio between two #rrggbb colors (1.0 to 21.0).
     */
    public static function contrastRatio(string $foreground, string $background): float
    {
        $a = self::relativeLuminance($foreground);
        $b = self::relativeLuminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Mix two colors in sRGB, like CSS color-mix(in srgb, $a $weight, $b).
     */
    public static function mix(string $a, string $b, float $weight): string
    {
        $rgbA = self::rgb($a);
        $rgbB = self::rgb($b);
        $hex = '#';

        foreach ([0, 1, 2] as $i) {
            $hex .= str_pad(dechex((int) round($rgbA[$i] * $weight + $rgbB[$i] * (1 - $weight))), 2, '0', STR_PAD_LEFT);
        }

        return $hex;
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
