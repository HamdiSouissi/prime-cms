<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Prime\Install\InstallerValidator;

final class InstallerValidatorTest extends TestCase
{
    private InstallerValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InstallerValidator();
    }

    public function testValidatesTablePrefixStrictFormat(): void
    {
        // Valid prefixes
        $this->assertNull($this->validator->validatePrefix('pc_'));
        $this->assertNull($this->validator->validatePrefix('prime2026_'));
        $this->assertNull($this->validator->validatePrefix('site_a1_'));

        // Invalid prefixes
        $this->assertNotNull($this->validator->validatePrefix(''));
        $this->assertNotNull($this->validator->validatePrefix('pc'));
        $this->assertNotNull($this->validator->validatePrefix('PC_'));
        $this->assertNotNull($this->validator->validatePrefix('pc-'));
        $this->assertNotNull($this->validator->validatePrefix('pc;drop_'));
        $this->assertNotNull($this->validator->validatePrefix('_'));
        $this->assertNotNull($this->validator->validatePrefix('___'));
        $this->assertNotNull($this->validator->validatePrefix('a_very_long_table_prefix_name_'));
    }

    public function testValidatesDatabaseStepInputs(): void
    {
        $valid = $this->validator->validateDatabase([
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_database' => 'prime_cms',
            'db_username' => 'prime',
            'db_prefix' => 'pc_',
        ]);
        $this->assertSame([], $valid);

        $invalid = $this->validator->validateDatabase([
            'db_host' => 'bad host',
            'db_port' => '99999',
            'db_database' => 'prime; DROP DATABASE',
            'db_username' => '',
            'db_prefix' => 'BAD',
        ]);
        $this->assertArrayHasKey('db_host', $invalid);
        $this->assertArrayHasKey('db_port', $invalid);
        $this->assertArrayHasKey('db_database', $invalid);
        $this->assertArrayHasKey('db_username', $invalid);
        $this->assertArrayHasKey('db_prefix', $invalid);
    }

    public function testValidatesSiteStepInputs(): void
    {
        $valid = $this->validator->validateSite([
            'site_name' => 'Atelier Horizon',
            'site_url' => 'https://horizon.example.fr',
            'site_locale' => 'fr',
            'timezone' => 'Europe/Paris',
        ]);
        $this->assertSame([], $valid);

        $invalid = $this->validator->validateSite([
            'site_name' => 'A',
            'site_url' => 'ftp://invalid.example',
            'site_locale' => 'de',
            'timezone' => 'Mars/Olympus',
        ]);
        $this->assertArrayHasKey('site_name', $invalid);
        $this->assertArrayHasKey('site_url', $invalid);
        $this->assertArrayHasKey('site_locale', $invalid);
        $this->assertArrayHasKey('timezone', $invalid);
    }

    public function testValidatesAdminAccountAndRejectsWeakOrPersonalPasswords(): void
    {
        $valid = $this->validator->validateAdmin([
            'display_name' => 'Camille Laurent',
            'email' => 'camille@horizon.fr',
            'password' => 'Ciel!Mandarine#842',
            'password_confirmation' => 'Ciel!Mandarine#842',
        ], 'Atelier Horizon');
        $this->assertSame([], $valid);

        // Too short
        $short = $this->validator->validatePassword('Short123!');
        $this->assertNotNull($short);

        // Common weak password even with >= 12 chars
        $this->assertNotNull($this->validator->validatePassword('password1234'));
        $this->assertNotNull($this->validator->validatePassword('123456789012'));
        $this->assertNotNull($this->validator->validatePassword('aaaaaaaaaaaa'));

        // Contains email local part or site name
        $this->assertNotNull($this->validator->validatePassword('camille-secret-2026', 'camille@horizon.fr'));
        $this->assertNotNull($this->validator->validatePassword('Atelier Horizon 2026!', 'admin@site.fr', 'Admin', 'Atelier Horizon'));

        // Mismatched confirmation
        $mismatch = $this->validator->validateAdmin([
            'display_name' => 'Camille',
            'email' => 'camille@horizon.fr',
            'password' => 'Ciel!Mandarine#842',
            'password_confirmation' => 'Different#Pass123',
        ], 'Atelier Horizon');
        $this->assertArrayHasKey('password_confirmation', $mismatch);
    }
}
