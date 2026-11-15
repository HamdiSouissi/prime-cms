<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use JsonException;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Settings\Settings;
use Throwable;

/**
 * Writes a JSON-typed setting into `pc_settings` (`settings:set <nom> <valeur-json>`).
 */
final class SettingsSetCommand implements CommandInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function name(): string
    {
        return 'settings:set';
    }

    public function description(): string
    {
        return 'Enregistre un réglage typé en JSON (`settings:set <nom> <valeur-json>`)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['no-autoload']);
        $name = trim($parsed['positionals'][0] ?? '');
        $rawJson = $parsed['positionals'][1] ?? null;
        $autoload = !ArgParser::hasFlag($parsed['options'], 'no-autoload');

        if ($name === '' || $rawJson === null) {
            $output->error('Usage : php bin/prime settings:set <nom> <valeur-json> [--no-autoload]');
            $output->error('Exemples : settings:set site_name \'"Mon Site"\'  |  settings:set maintenance false');

            return self::FAILURE;
        }

        try {
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $output->error(sprintf('Valeur JSON invalide pour "%s" : utilisez un JSON valide (ex. \'"texte"\', 42, true, null, ["a"]).', $name));

            return self::FAILURE;
        }

        try {
            $this->settings->set($name, $decoded, $autoload);
        } catch (Throwable $exception) {
            $output->error('Impossible d\'enregistrer le réglage : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('Réglage "%s" enregistré.', $name));

        return self::SUCCESS;
    }
}
