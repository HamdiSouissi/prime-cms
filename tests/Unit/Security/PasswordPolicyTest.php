<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Prime\Security\PasswordPolicy;

final class PasswordPolicyTest extends TestCase
{
    public function testEnforcesMinMaxCommonPasswordsAndPersonalContext(): void
    {
        $policy = new PasswordPolicy();

        // Too short (< 12 chars)
        $this->assertNotNull($policy->validate('Ab1!Short99'));

        // Too long (> 4096 chars)
        $this->assertNotNull($policy->validate(str_repeat('X7!k', 1025)));

        // Repeated character
        $this->assertNotNull($policy->validate('aaaaaaaaaaaa'));

        // Common weak passwords from dictionary (even >=12 chars)
        $this->assertNotNull($policy->validate('password123456'));
        $this->assertNotNull($policy->validate('motdepasse123'));
        $this->assertNotNull($policy->validate('Azerty123456'));
        $this->assertNotNull($policy->validate('correcthorsebatterystaple'));

        // Personal info (email, local part, display name, site name)
        $this->assertNotNull($policy->validate('MonSecret_camille@example.fr', ['email' => 'camille@example.fr']));
        $this->assertNotNull($policy->validate('SuperCamille!9921', ['email' => 'camille@example.fr']));
        $this->assertNotNull($policy->validate('SuperLaurent!9921', ['display_name' => 'Camille Laurent']));
        $this->assertNotNull($policy->validate('AtelierLumiere!2026', ['site_name' => 'AtelierLumiere']));

        // Strong passphrases pass
        $this->assertNull($policy->validate('Saffron!Horizon#8429', [
            'email' => 'camille@example.fr',
            'display_name' => 'Camille Laurent',
            'site_name' => 'Mon Blog',
        ]));
        $this->assertTrue($policy->passes('Velours-Mandarine-7391!'));
    }
}
