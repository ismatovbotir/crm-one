<?php

namespace Tests\Unit\Support;

use App\Support\CustomerNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spec 01 section 6.3: names normalised for duplicate warnings.
 */
class CustomerNameNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'english legal form' => ['Alpha Trade LLC', 'alpha trade'],
            'uzbek quotes and form' => ['«ALPHA TRADE» MChJ', 'alpha trade'],
            'russian form cyrillic' => ['ООО "Альфа Трейд"', 'альфа трейд'],
            'extra spaces and punctuation' => ['  Alpha,   Trade.  ', 'alpha trade'],
            'form in the middle is removed' => ['Navoiy LLC Mining', 'navoiy mining'],
        ];
    }

    #[DataProvider('names')]
    public function test_normalizes(string $input, string $expected): void
    {
        $this->assertSame($expected, CustomerNameNormalizer::normalize($input));
    }
}
