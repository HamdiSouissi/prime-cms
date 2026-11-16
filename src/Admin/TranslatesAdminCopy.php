<?php

declare(strict_types=1);

namespace Prime\Admin;

use Prime\I18n\Translator;

/**
 * Catalogue lookup shared by the admin controllers and services.
 *
 * Admin copy lives in `resources/lang/{locale}/admin.php`. Controllers build view data away from
 * a `TemplateContext`, so this trait keeps every lookup going through the same translator.
 */
trait TranslatesAdminCopy
{
    /**
     * Translate a catalogue key.
     *
     * @param array<string, string|int|float> $replace
     */
    protected function t(string $key, array $replace = []): string
    {
        return Translator::fallback()->translate($key, $replace);
    }
}
