<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use JsonException;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Settings\Settings;
use Prime\Settings\SettingsField;
use Prime\Settings\SettingsRegistry;
use Throwable;

/**
 * Lists declared settings — name, section, type, stored value and default — so an operator can audit
 * what a screen will render (including what themes and plugins contributed) without a browser.
 */
final class SettingsListCommand implements CommandInterface
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly Settings $settings,
    ) {
    }

    public function name(): string
    {
        return 'settings:list';
    }

    public function description(): string
    {
        return 'Liste les réglages déclarés, leur valeur et leur défaut (`settings:list [section] [--json]`)';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['json', 'changes-only', 'reveal']);
        $section = trim((string) ($parsed['positionals'][0] ?? ''));
        $sections = $this->registry->sections();

        if ($section !== '' && !isset($sections[$section])) {
            $output->error(sprintf('Section inconnue : « %s ».', $section));
            $output->writeln('Sections disponibles : ' . implode(', ', array_keys($sections)));

            return self::FAILURE;
        }

        $fields = $this->registry->fields($section !== '' ? $section : null);
        if ($fields === []) {
            $output->writeln($section === '' ? 'Aucun réglage déclaré.' : sprintf('Aucun réglage dans la section « %s ».', $section));

            return self::SUCCESS;
        }

        $reveal = ArgParser::hasFlag($parsed['options'], 'reveal');
        $changesOnly = ArgParser::hasFlag($parsed['options'], 'changes-only');

        $rows = [];
        $payload = [];

        foreach ($fields as $field) {
            $value = $this->read($field);

            if ($changesOnly && $this->normalize($value) === $this->normalize($field->default)) {
                continue;
            }

            $payload[] = [
                'name' => $field->name,
                'label' => $field->label,
                'section' => $field->section,
                'type' => $field->type,
                'value' => $field->isSensitive() && !$reveal ? null : $value,
                'default' => $field->default,
                'stored' => $this->isStored($field),
                'encrypted' => $field->isSensitive(),
                'public' => $field->public,
            ];

            $rows[] = [
                $field->name,
                $field->section,
                $field->type,
                $this->render($value, $field, $reveal),
                $this->render($field->default, $field, true),
            ];
        }

        if (ArgParser::hasFlag($parsed['options'], 'json')) {
            return $this->renderJson($payload, $output);
        }

        if ($rows === []) {
            $output->writeln('Aucun écart par rapport aux valeurs par défaut.');

            return self::SUCCESS;
        }

        $output->table(['Réglage', 'Section', 'Type', 'Valeur', 'Défaut'], $rows);
        $output->writeln(sprintf('%d réglage(s) — section « %s ».', count($rows), $section !== '' ? $section : 'toutes'));

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $payload
     */
    private function renderJson(array $payload, ConsoleOutput $output): int
    {
        try {
            $output->writeln((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        } catch (JsonException) {
            $output->error('Encodage JSON impossible.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function read(SettingsField $field): mixed
    {
        try {
            if ($field->isSensitive()) {
                return $this->settings->getSecret($field->name);
            }

            $value = $this->settings->get($field->name, null);
        } catch (Throwable) {
            // Settings are unreachable before installation: show the declared default.
            return $field->default;
        }

        return $this->isStored($field) ? $value : $field->default;
    }

    private function isStored(SettingsField $field): bool
    {
        try {
            return $this->settings->has($field->name);
        } catch (Throwable) {
            return false;
        }
    }

    private function render(mixed $value, SettingsField $field, bool $reveal): string
    {
        if ($field->isSensitive()) {
            if (!is_string($value) || $value === '') {
                return '—';
            }

            return $reveal ? $value : sprintf('••• (%d)', strlen($value));
        }

        if (is_bool($value)) {
            return $value ? 'oui' : 'non';
        }

        if ($value === null || $value === '') {
            return '—';
        }

        if (is_array($value)) {
            $flat = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($flat)) {
                return '[…]';
            }

            return mb_strlen($flat) > 48 ? mb_substr($flat, 0, 45) . '…' : $flat;
        }

        if (is_object($value)) {
            return get_class($value);
        }

        $string = (string) $value;

        return mb_strlen($string) > 48 ? mb_substr($string, 0, 45) . '…' : $string;
    }

    private function normalize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            $flat = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($flat) ? $flat : '';
        }

        return (string) $value;
    }
}
