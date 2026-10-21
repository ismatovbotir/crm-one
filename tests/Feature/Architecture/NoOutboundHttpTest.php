<?php

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Spec 02 rev. 3 AC-I.47c: 1C is on a private network and is never called by the CRM, so no HTTP
 * client may exist in app/ (no Http facade, Guzzle, cURL or URL stream reads). The single allowed
 * exception is the Telegram Bot API client (Spec 03 4.6), which may only target api.telegram.org.
 */
class NoOutboundHttpTest extends TestCase
{
    public function test_application_code_contains_no_http_client(): void
    {
        $patterns = [
            '/\bIlluminate\\\\Support\\\\Facades\\\\Http\b/',
            '/\bHttp::/',
            '/\bGuzzleHttp\\\\/',
            '/\bcurl_(init|exec)\s*\(/',
            '/\b(file_get_contents|fopen)\s*\(\s*[\'"]https?:/',
            '/\bOneCClient\b/',
        ];
        $offenders = [];
        $telegram = 'Services'.DIRECTORY_SEPARATOR.'Telegram'.DIRECTORY_SEPARATOR.'TelegramClient.php';

        foreach (File::allFiles(app_path()) as $file) {
            $contents = $file->getContents();

            if ($file->getRelativePathname() === $telegram) {
                preg_match_all('#https?://[a-z0-9.-]+#i', $contents, $hosts);
                $this->assertSame(['https://api.telegram.org'], array_values(array_unique($hosts[0])), 'The Telegram client may only call api.telegram.org.');
                $this->assertDoesNotMatchRegularExpression('/OneC|1c/i', $contents);

                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $offenders[] = $file->getRelativePathname().' matches '.$pattern;
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
