<?php

namespace App\Enums;

/**
 * One-click color presets. Applying a preset only fills the settings form; it is not saved until the admin clicks Save.
 */
enum ThemePreset: string
{
    case Default = 'default';
    case DarkRed = 'dark_red';
    case Mono = 'mono';
    case Navy = 'navy';

    public function label(): string
    {
        return __('settings.presets.'.$this->value);
    }

    /**
     * @return array<string, string> ThemeColor value => hex
     */
    public function colors(): array
    {
        return match ($this) {
            self::Default => ThemeColor::defaults(),
            self::DarkRed => [
                ThemeColor::Primary->value => '#9b0018',
                ThemeColor::PrimaryHover->value => '#6e0011',
                ThemeColor::Sidebar->value => '#c0c4cc',
                ThemeColor::SidebarText->value => '#111827',
                ThemeColor::Surface->value => '#ffffff',
                ThemeColor::Text->value => '#1f2937',
                ThemeColor::Border->value => '#b4b9c2',
            ],
            self::Mono => [
                ThemeColor::Primary->value => '#4b5563',
                ThemeColor::PrimaryHover->value => '#1f2937',
                ThemeColor::Sidebar->value => '#d5d8de',
                ThemeColor::SidebarText->value => '#1f2937',
                ThemeColor::Surface->value => '#ffffff',
                ThemeColor::Text->value => '#1f2937',
                ThemeColor::Border->value => '#c0c4cc',
            ],
            self::Navy => [
                ThemeColor::Primary->value => '#2563eb',
                ThemeColor::PrimaryHover->value => '#1e3a8a',
                ThemeColor::Sidebar->value => '#0f172a',
                ThemeColor::SidebarText->value => '#e2e8f0',
                ThemeColor::Surface->value => '#ffffff',
                ThemeColor::Text->value => '#0f172a',
                ThemeColor::Border->value => '#cbd5e1',
            ],
        };
    }
}
