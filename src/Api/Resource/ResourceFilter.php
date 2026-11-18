<?php

declare(strict_types=1);

namespace Prime\Api\Resource;

use Prime\Hooks\Hooks;
use Throwable;

/**
 * Runs the `api.resource.{name}` filter defensively.
 *
 * A plugin may add or adjust members of a serialised resource, but whatever it returns is
 * normalised: an exception, a non-array result or a result that drops the `id` member is
 * ignored and the core representation is kept.
 */
final class ResourceFilter
{
    public function __construct(private readonly Hooks $hooks)
    {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function apply(string $resource, array $data, mixed ...$context): array
    {
        try {
            $filtered = $this->hooks->apply('api.resource.' . $resource, $data, ...$context);
        } catch (Throwable) {
            return $data;
        }

        if (!is_array($filtered) || ($filtered['id'] ?? null) !== ($data['id'] ?? null)) {
            return $data;
        }

        $normalised = [];
        foreach ($filtered as $key => $value) {
            if (is_string($key)) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }
}
