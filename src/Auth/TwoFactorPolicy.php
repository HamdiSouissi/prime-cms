<?php

declare(strict_types=1);

namespace Prime\Auth;

use Prime\Settings\Settings;
use Throwable;

/**
 * Decides which accounts must use double-factor authentication.
 *
 * The roles list lives in `security.two_factor_required_roles` so an administrator can force 2FA
 * for every privileged account; enforcement then happens in the `auth` middleware, which keeps the
 * user authenticated but redirects them to their profile until 2FA (or a saved bypass) is set up.
 *
 * Nothing is enforced until `security.two_factor_enabled` is switched on: locking the sole
 * administrator behind a device they have not paired yet is the one failure mode a CMS cannot
 * recover from by clicking around, so the master switch is opt-in and defaults to off.
 */
final class TwoFactorPolicy
{
    public const SETTING_ROLES = 'security.two_factor_required_roles';

    public const SETTING_ENABLED = 'security.two_factor_enabled';

    public const SETTING_ISSUER = 'security.two_factor_issuer';

    /**
     * Route users are held on while they have not enrolled yet.
     */
    public const ENROLLMENT_URL = '/admin/profile?enroll=two-factor';

    /**
     * @var list<string>
     */
    private readonly array $requiredRoles;

    private readonly bool $enabled;

    private readonly string $issuer;

    public function __construct(?Settings $settings = null)
    {
        $roles = [];
        $enabled = false;
        $issuer = 'Prime CMS';

        if ($settings !== null) {
            try {
                $raw = $settings->getArray(self::SETTING_ROLES, ['administrator']);
                foreach ($raw as $role) {
                    if (is_scalar($role)) {
                        $clean = trim((string) $role);
                        if ($clean !== '') {
                            $roles[] = $clean;
                        }
                    }
                }

                $enabled = $settings->getBool(self::SETTING_ENABLED, false);
                $issuer = trim($settings->getString(self::SETTING_ISSUER, 'Prime CMS')) ?: 'Prime CMS';
            } catch (Throwable) {
                // Settings are unreachable before installation: keep the safe defaults.
                $roles = [];
            }
        }

        $this->requiredRoles = array_values(array_unique($roles));
        $this->enabled = $enabled;
        $this->issuer = $issuer;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    /**
     * @return list<string>
     */
    public function requiredRoles(): array
    {
        return $this->requiredRoles;
    }

    /**
     * Whether the account belongs to a role for which 2FA is mandatory and has not enrolled yet.
     */
    public function isRequired(User $user): bool
    {
        if (!$this->enabled || $user->hasTwoFactor() || $this->requiredRoles === []) {
            return false;
        }

        foreach ($this->requiredRoles as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user may continue into the admin screens for this request.
     */
    public function allowsAccess(User $user): bool
    {
        return !$this->isRequired($user);
    }
}
