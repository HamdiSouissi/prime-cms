<?php

declare(strict_types=1);

namespace Prime\Install;

/**
 * Contract for determining whether Prime CMS has completed installation.
 */
interface InstallCheckerInterface
{
    public function isInstalled(): bool;
}
