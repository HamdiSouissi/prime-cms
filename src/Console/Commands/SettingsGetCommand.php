<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Settings\Settings;
use Throwable;

/**
 * Reads a setting from `pc_settings` and outputs its JSON representation.
 */
final class SettingsGetCommand implements CommandInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function name(): string
    {
        return 'settings:get';
    }

    public function description(): string
    {
        return 'Lit la valeur JSON d\'un réglage (`settings:get <nom>`)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $name = trim($parsed['positionals'][0] ?? '');

        if ($name === '') {
            $output->error('Usage : php bin/prime settings:get <nom>');

            return self::FAILURE;
        }

        try {
            if (!$this->settings->has($name)) {
                $output->error(sprintf('Réglage introuvable : "%s".', $name));

                return self::FAILURE;
            }

            $value = $this->settings->get($name);
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            $output->error('Erreur lors de la lecture du réglage : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln($encoded);

        return self::SUCCESS;
    }
}
