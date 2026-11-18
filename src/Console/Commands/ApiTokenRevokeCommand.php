<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Api\Repository\ApiTokenRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;

/**
 * Révoque (supprime) un jeton d'API par son ID (`bin/prime api:token:revoke`).
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
        return 'Révoque un jeton d\'API par son ID (--token-id).';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $opts = $parsed['options'];

        $tokenIdRaw = ArgParser::stringOption($opts, 'token-id');

        if ($tokenIdRaw === '' || !ctype_digit($tokenIdRaw)) {
            $output->error('Option --token-id requise (identifiant du jeton).');

            return self::FAILURE;
        }

        $tokenId = (int) $tokenIdRaw;

        $success = $this->tokens->revoke($tokenId);

        if (!$success) {
            $output->error(sprintf('Jeton #%d introuvable.', $tokenId));

            return self::FAILURE;
        }

        $output->writeln(sprintf('Jeton #%d révoqué.', $tokenId));

        return self::SUCCESS;
    }
}
