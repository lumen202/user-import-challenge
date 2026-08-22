<?php

declare(strict_types=1);

namespace UserImport\Tests\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UserImport\Import\UserNormalizer;

#[CoversClass(UserNormalizer::class)]
final class UserNormalizerTest extends TestCase
{
    private UserNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new UserNormalizer();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nameProvider(): array
    {
        return [
            'lowercase' => ['john', 'John'],
            'uppercase' => ['SMITH', 'Smith'],
            'mixed case' => ['mArY', 'Mary'],
            'hyphenated' => ['mary-jane', 'Mary-Jane'],
            'accented' => ['éLODIE', 'Élodie'],
            'multibyte umlaut' => ['MÜLLER', 'Müller'],
            'surrounding whitespace' => ["  jane\t", 'Jane'],
            'two words' => ['van helsing', 'Van Helsing'],
            'empty stays empty' => ['', ''],
            'whitespace only becomes empty' => ['   ', ''],
        ];
    }

    #[DataProvider('nameProvider')]
    public function testNormalizeName(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalizeName($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function emailProvider(): array
    {
        return [
            'uppercase' => ['JOHN.SMITH@EXAMPLE.COM', 'john.smith@example.com'],
            'mixed case' => ['John.Smith@Example.Com', 'john.smith@example.com'],
            'surrounding whitespace' => [' spaces@example.com ', 'spaces@example.com'],
            'already normalised' => ['ok@example.com', 'ok@example.com'],
            'empty stays empty' => ['', ''],
        ];
    }

    #[DataProvider('emailProvider')]
    public function testNormalizeEmail(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalizeEmail($input));
    }
}
