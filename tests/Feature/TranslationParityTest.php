<?php

namespace Tests\Feature;

use App\Enums\Locale;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Guards the i18n rule: every user-facing string is a translation key that exists,
 * non-empty, in uz, ru and en.
 */
class TranslationParityTest extends TestCase
{
    public function test_every_locale_has_the_same_translation_files(): void
    {
        $reference = $this->langFiles(Locale::En);

        foreach (Locale::cases() as $locale) {
            $this->assertSame($reference, $this->langFiles($locale), "lang/{$locale->value} has a different set of files.");
            $this->assertFileExists(lang_path($locale->value.'.json'));
        }
    }

    public function test_every_key_exists_in_every_locale(): void
    {
        $keys = [];

        foreach (Locale::cases() as $locale) {
            $keys[$locale->value] = array_keys($this->translations($locale));
        }

        foreach ($keys as $locale => $own) {
            foreach ($keys as $other => $otherKeys) {
                $missing = array_diff($otherKeys, $own);

                $this->assertSame([], array_values($missing), "Keys present in [{$other}] but missing in [{$locale}].");
            }
        }
    }

    public function test_no_translation_is_empty(): void
    {
        foreach (Locale::cases() as $locale) {
            $empty = array_keys(array_filter($this->translations($locale), fn (mixed $value): bool => ! is_string($value) || trim($value) === ''));

            $this->assertSame([], $empty, "Empty translations in [{$locale->value}].");
        }
    }

    public function test_placeholders_match_across_locales(): void
    {
        $english = $this->translations(Locale::En);

        foreach ([Locale::Uz, Locale::Ru] as $locale) {
            foreach ($this->translations($locale) as $key => $value) {
                $this->assertSame(
                    $this->placeholders($english[$key] ?? ''),
                    $this->placeholders($value),
                    "Placeholders of [{$key}] differ between en and {$locale->value}.",
                );
            }
        }
    }

    /**
     * Every literal __('group.key') in app/ and resources/views must resolve in all locales,
     * and literal sentences (the pre-i18n style) are not allowed in our own code.
     */
    public function test_every_literal_key_used_in_code_exists(): void
    {
        $groups = $this->langFiles(Locale::En);
        $json = array_keys(json_decode((string) File::get(lang_path('en.json')), true));
        $files = [...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))];
        $problems = [];

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            preg_match_all("/(?:__|trans|trans_choice)\\(\\s*'([^']+)'\\s*[,)]/", $file->getContents(), $matches);

            foreach ($matches[1] as $key) {
                $group = explode('.', $key, 2)[0];

                if (! in_array($group, $groups, true) || ! str_contains($key, '.')) {
                    if (! in_array($key, $json, true)) {
                        $problems[] = "{$file->getRelativePathname()}: '{$key}' is not a translation key";
                    }

                    continue;
                }

                foreach (Locale::cases() as $locale) {
                    if (! trans()->has($key, $locale->value, false)) {
                        $problems[] = "{$file->getRelativePathname()}: '{$key}' is missing in {$locale->value}";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }

    /**
     * @return list<string>
     */
    private function langFiles(Locale $locale): array
    {
        $groups = array_map(fn (SplFileInfo $file): string => $file->getFilenameWithoutExtension(), File::files(lang_path($locale->value)));
        sort($groups);

        return $groups;
    }

    /**
     * Flattened "group.key" => value for PHP files, plus "json:key" => value for the JSON file.
     *
     * @return array<string, mixed>
     */
    private function translations(Locale $locale): array
    {
        $flat = [];

        foreach ($this->langFiles($locale) as $group) {
            foreach (Arr::dot(require lang_path("{$locale->value}/{$group}.php")) as $key => $value) {
                $flat["{$group}.{$key}"] = $value;
            }
        }

        foreach (json_decode((string) File::get(lang_path($locale->value.'.json')), true) as $key => $value) {
            $flat["json:{$key}"] = $value;
        }

        return $flat;
    }

    /**
     * @return list<string>
     */
    private function placeholders(mixed $value): array
    {
        preg_match_all('/:([a-z_]+)/', (string) $value, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
