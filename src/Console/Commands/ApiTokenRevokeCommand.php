<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Api\Repository\ApiTokenRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;

/**
 * Révoque un jeton d'API par son ID (`bin/prime api:token:revoke`).
 */
final class ApiTokenRevokeCommand implements CommandInterface
{
    public function __construct(private readonly ApiTokenRepository $tokens)
    {
    }

    public function name(): string
    {
        return 'api:token:revoke';
    }

    public function description(): string
    {
        return 'Révoque un jeton d\'API par son ID (identifiant en argument).';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $positionals = $parsed['positionals'];

        $tokenIdRaw = $positionals[0] ?? '';
        if ($tokenIdRaw === '' || !ctype_digit($tokenIdRaw)) {
            $output->error('Identifiant du jeton requis en argument.');

            return self::FAILURE;
        }

        $tokenId = (int) $tokenIdRaw;

        $token = $this->tokens->findById($tokenId);
        if ($token === null) {
            $output->error(sprintf('Jeton #%d introuvable.', $tokenId));

            return self::FAILURE;
        }

        $this->tokens->revoke($tokenId);

        $output->writeln(sprintf('Jeton #%d révoqué.', $tokenId));

        return self::SUCCESS;
    }
}
