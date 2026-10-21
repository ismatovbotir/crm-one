<?php

namespace Tests\Feature;

use App\Enums\Locale;
use Illuminate\Support\Facades\File;
use ReflectionEnum;
use Tests\TestCase;

/**
 * Spec 03 section 9: every enum case shown to users has a translated label in uz, ru and en.
 */
class EnumLabelTranslationTest extends TestCase
{
    public function test_every_enum_case_has_a_label_in_every_locale(): void
    {
        $problems = [];

        foreach (File::files(app_path('Enums')) as $file) {
            $class = 'App\\Enums\\'.$file->getFilenameWithoutExtension();

            if (! enum_exists($class) || ! method_exists($class, 'label')) {
                continue;
            }

            foreach ((new ReflectionEnum($class))->getCases() as $case) {
                foreach (Locale::cases() as $locale) {
                    app()->setLocale($locale->value);
                    $label = $case->getValue()->label();

                    if (trim($label) === '' || preg_match('/^[a-z_]+(\.[a-z0-9_]+)+$/', $label) === 1) {
                        $problems[] = "{$class}::{$case->getName()} has no {$locale->value} label ({$label})";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }
}
