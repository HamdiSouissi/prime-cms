<?php

declare(strict_types=1);

namespace Prime\Content;

/**
 * Resolves a declared label: translation keys go through `t()`, literals are returned as is.
 *
 * Core registries declare keys (`core.content_types.post.plural`) so that labels follow the
 * locale of the current user; plugins may declare namespaced keys (`hello-prime::…`) or plain
 * literals when they ship a single language.
 */
final class LabelTranslator
{
    private const KEY_PATTERN = '/^(?:[a-z0-9][a-z0-9_-]*::)?[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)+$/';

    public static function isKey(string $value): bool
    {
        return preg_match(self::KEY_PATTERN, $value) === 1;
    }

    public static function resolve(string $value): string
    {
        if (!self::isKey($value)) {
            return $value;
        }

        $translated = t($value);

        return $translated !== '' ? $translated : $value;
    }
}
