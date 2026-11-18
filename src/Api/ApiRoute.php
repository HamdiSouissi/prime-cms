<?php

declare(strict_types=1);

namespace Prime\Api;

/**
 * Déclaration déclarative d'une route de l'API REST Prime CMS.
 *
 * Chaque route `/api/v1` doit être décrite par une instance de cette classe.
 * Une route doit exposer soit une permission requise, soit `public: true`
 * explicite. Aucune route publique par défaut.
 *
 * @param array<string, mixed> $parameters   Noms et schémas des paramètres de route/query
 * @param array<string, mixed> $response     Schéma de réponse OpenAPI-like
 */
final class ApiRoute
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string|\Closure $handler,
        public readonly ?string $permission,
        public readonly bool $public,
        public readonly string $summary,
        /** @param array<string, mixed> $parameters */
        public readonly array $parameters = [],
        /** @param array<string, mixed> $response */
        public readonly array $response = [],
    ) {
        if ($this->permission === null && !$this->public) {
            throw new \InvalidArgumentException(
                sprintf(
                    'La route %s %s doit déclarer une permission ou public: true.',
                    $this->method,
                    $this->path,
                ),
            );
        }
    }

    /**
     * @return array{method: string, path: string, handler: string|\Closure, permission: string|null, public: bool, summary: string, parameters: list<string>, response: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'handler' => is_string($this->handler) ? $this->handler : \Closure::class,
            'permission' => $this->permission,
            'public' => $this->public,
            'summary' => $this->summary,
            'parameters' => array_keys($this->parameters),
            'response' => $this->response,
        ];
    }
}
