<?php

namespace Tests\Feature\Security;

use App\Livewire\Settings\Appearance;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Settings > Appearance logo upload (CWE-79 stored XSS via SVG, CWE-434 type spoofing).
 * Uploaded logos are served from our own origin under /storage, so a script-bearing SVG
 * executes in the CRM origin when the file URL is opened directly.
 *
 * Tests marked "FINDING" fail against the current code and pass once the fix is applied.
 */
class LogoUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPTED_SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * FINDING: the SVG blacklist only runs when the client-supplied extension is "svg",
     * but `mimes` and `store()` use the sniffed type. A scripted SVG named logo.png passes
     * validation and is stored with an .svg extension (verified outside the test runner:
     * finfo sniffs image/svg+xml, guessExtension()=svg, hashName()=branding/<random>.svg).
     * Under PHPUnit Livewire fakes the MIME from the client name, so here we assert on content.
     */
    public function test_svg_content_with_a_raster_extension_is_not_stored_as_svg(): void
    {
        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.png', self::SCRIPTED_SVG))
            ->call('uploadLogo');

        $path = Setting::get(Setting::LOGO_KEY);

        if ($path !== null) {
            $this->assertStringEndsNotWith('.svg', strtolower($path), 'Spoofed SVG was stored as .svg');
            $this->assertStringNotContainsStringIgnoringCase('<script', Storage::disk('public')->get($path));
        } else {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * FINDING: regex blacklist bypasses (namespaced script element, entity-encoded javascript: URL,
     * XHTML-namespaced iframe, animate/set that writes an href).
     */
    #[DataProvider('blacklistBypasses')]
    public function test_script_bearing_svg_variants_are_rejected(string $svg): void
    {
        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.svg', $svg))
            ->call('uploadLogo')
            ->assertHasErrors(['logo']);

        $this->assertNull(Setting::get(Setting::LOGO_KEY));
    }

    public static function blacklistBypasses(): array
    {
        return [
            'prefixed script element' => ['<svg xmlns="http://www.w3.org/2000/svg"><s:script xmlns:s="http://www.w3.org/2000/svg">alert(1)</s:script></svg>'],
            'entity-encoded javascript url' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript&#58;alert(1)"><rect width="10" height="10"/></a></svg>'],
            'xhtml iframe' => ['<svg xmlns="http://www.w3.org/2000/svg"><h:iframe xmlns:h="http://www.w3.org/1999/xhtml" src="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;"/></svg>'],
            'animate rewrites href' => ['<svg xmlns="http://www.w3.org/2000/svg"><a><animate attributeName="href" values="javascript&#x3a;alert(1)"/><text y="10">x</text></a></svg>'],
        ];
    }

    public function test_plain_script_and_event_handlers_are_rejected(): void
    {
        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.svg', self::SCRIPTED_SVG))
            ->call('uploadLogo')
            ->assertHasErrors(['logo']);

        $this->assertNull(Setting::get(Setting::LOGO_KEY));
    }

    public function test_php_payload_disguised_as_image_is_rejected(): void
    {
        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.php', '<?php echo 1;'))
            ->call('uploadLogo')
            ->assertHasErrors(['logo']);

        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.png.php', '<?php echo 1;'))
            ->call('uploadLogo')
            ->assertHasErrors(['logo']);

        $this->assertNull(Setting::get(Setting::LOGO_KEY));
    }

    public function test_stored_logo_gets_a_random_name(): void
    {
        Livewire::test(Appearance::class)
            ->set('logo', UploadedFile::fake()->image('../../evil.png', 64, 64))
            ->call('uploadLogo')
            ->assertHasNoErrors();

        $path = (string) Setting::get(Setting::LOGO_KEY);
        $this->assertStringStartsWith(Setting::LOGO_DIRECTORY.'/', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('evil', $path);
    }
}
