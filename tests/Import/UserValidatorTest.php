<?php

declare(strict_types=1);

namespace UserImport\Tests\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UserImport\Import\UserValidator;

#[CoversClass(UserValidator::class)]
final class UserValidatorTest extends TestCase
{
    public function testAcceptsAValidRow(): void
    {
        $validator = new UserValidator();

        self::assertNull($validator->validate(2, 'John', 'Smith', 'john.smith@example.com'));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function missingFieldProvider(): array
    {
        return [
            'missing name' => ['', 'Smith', 'a@example.com', 'missing name'],
            'missing surname' => ['John', '', 'a@example.com', 'missing surname'],
            'missing email' => ['John', 'Smith', '', 'missing email'],
            'missing several' => ['', '', 'a@example.com', 'missing name, surname'],
            'missing everything' => ['', '', '', 'missing name, surname, email'],
        ];
    }

    #[DataProvider('missingFieldProvider')]
    public function testRejectsMissingFields(string $name, string $surname, string $email, string $expected): void
    {
        $validator = new UserValidator();

        self::assertSame($expected, $validator->validate(2, $name, $surname, $email));
    }

    /**
     * Every invalid email shape from the sample file, plus the spec examples.
     *
     * @return array<string, array{string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'no at sign' => ['invalid-email'],
            'missing domain' => ['missing@'],
            'double at' => ['bad@@example.com'],
            'two full addresses' => ['john@example.com@example.com'],
            'space inside' => ['jo hn@example.com'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function testRejectsInvalidEmails(string $email): void
    {
        $validator = new UserValidator();

        $error = $validator->validate(2, 'John', 'Smith', $email);

        self::assertNotNull($error);
        self::assertStringContainsString('invalid email address', $error);
    }

    public function testRejectsDuplicateEmailAndNamesTheFirstLine(): void
    {
        $validator = new UserValidator();

        self::assertNull($validator->validate(2, 'John', 'Smith', 'john.smith@example.com'));
        self::assertSame(
            'duplicate email (first seen on line 2)',
            $validator->validate(44, 'Duplicate', 'User', 'john.smith@example.com'),
        );
    }

    public function testAnInvalidRowDoesNotReserveItsEmail(): void
    {
        $validator = new UserValidator();

        // Rejected for a missing name - its email must not count as "seen".
        self::assertSame('missing name', $validator->validate(2, '', 'Smith', 'a@example.com'));
        self::assertNull($validator->validate(3, 'John', 'Smith', 'a@example.com'));
    }

    public function testSeenStateIsPerInstance(): void
    {
        $first = new UserValidator();
        self::assertNull($first->validate(2, 'John', 'Smith', 'a@example.com'));

        // A fresh validator (a new file/run) must not remember earlier emails.
        $second = new UserValidator();
        self::assertNull($second->validate(2, 'John', 'Smith', 'a@example.com'));
    }
}
