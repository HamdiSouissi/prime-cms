<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\I18n\LanguageChecker;
use Prime\I18n\Translator;
use Prime\Kernel\Application;

/**
 * Verifies that every shipped locale exposes the same keys as the reference language, that no
 * template uses an unknown translation key, and reports hard-coded literals.
 */
final class LangCheckCommand implements CommandInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'lang:check';
    }

    public function description(): string
    {
        return 'Vérifie la cohérence des catalogues de traduction (--json, --catalog, --locales=fr,en). Toute clé manquante, clé inconnue ou texte en dur fait échouer la commande ; --strict fait aussi échouer les avertissements (clés inutilisées, substituts différents).';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $strict = in_array('--strict', $args, true);
        $asJson = in_array('--json', $args, true);
        $noUsage = in_array('--catalog', $args, true);

        $checker = $this->checker($args);
        $report = $checker->report();

        if ($noUsage) {
            $report = $this->catalogOnly($checker, $report);
        }

        if ($asJson) {
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $output->writeln(is_string($encoded) ? $encoded : '{}');

            return $report['ok'] === true ? self::SUCCESS : self::FAILURE;
        }

        $output->writeln(sprintf(
            'Langue de référence : %s — locales disponibles : %s',
            $report['reference'],
            $report['locales'] === [] ? 'aucune' : implode(', ', $report['locales']),
        ));
        $output->writeln('');

        foreach ($report['catalogue'] as $locale => $data) {
            $counts = sprintf(
                '  %s: %d manquante(s), %d absente(s) de la référence, %d vide(s), %d substitut(s) différent(s)',
                (string) $locale,
                count($data['missing']),
                count($data['extra']),
                count($data['empty']),
                count($data['placeholders']),
            );
            $output->writeln($counts);

            foreach (array_slice($data['missing'], 0, 20) as $key) {
                $output->writeln(sprintf('    - manquante : %s', (string) $key));
            }

            foreach (array_slice($data['empty'], 0, 20) as $key) {
                $output->writeln(sprintf('    - vide : %s', (string) $key));
            }

            foreach (array_slice($data['placeholders'], 0, 20) as $key) {
                $output->writeln(sprintf('    - substituts : %s', (string) $key));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('Clés utilisées dans les sources : %d', $report['used']));

        foreach (array_slice(array_keys($report['unknown']), 0, 30) as $key) {
            $output->writeln(sprintf('  ! clé inconnue : %s (%s)', (string) $key, implode(', ', $report['unknown'][(string) $key])));
        }

        foreach (array_slice($report['unused'], 0, 30) as $key) {
            $output->writeln(sprintf('  - clé jamais utilisée : %s', (string) $key));
        }

        foreach (array_slice($report['hardcoded'], 0, 30) as $hit) {
            $output->writeln(sprintf(
                '  - texte en dur : %s:%d — %s',
                (string) $hit['file'],
                (int) $hit['line'],
                (string) $hit['text'],
            ));
        }

        $errors = count($report['errors']);
        $warnings = count($report['warnings']);

        $output->writeln('');

        $output->writeln(match (true) {
            $errors === 0 && $warnings === 0 => 'Catalogue de traduction cohérent.',
            default => sprintf('%d erreur(s), %d avertissement(s).', $errors, $warnings),
        });

        // `--strict` additionally fails on warnings: unused keys and placeholder drift.
        $failed = $errors !== 0 || ($strict && $warnings !== 0);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<int, string> $args
     */
    private function checker(array $args): LanguageChecker
    {
        $locales = $this->option($args, '--locales');
        $allowed = $locales === null
            ? null
            : array_values(array_filter(array_map(
                fn (string $locale): string => $this->translator()->normalize($locale),
                explode(',', $locales),
            ), static fn (string $locale): bool => $locale !== ''));

        return new LanguageChecker(
            langPath: $this->app->resourcesPath('lang'),
            reference: $this->translator()->locale(),
            scanPaths: array_values(array_filter([
                $this->app->resourcesPath('views'),
                $this->app->basePath('src'),
                $this->app->contentPath('themes'),
                $this->app->contentPath('plugins'),
                $this->app->basePath('public/assets/admin/js'),
            ], static fn (string $path): bool => is_dir($path))),
            locales: $allowed,
            // The suite references internal and deliberately-missing keys: usage only.
            usageOnlyPaths: array_filter(
                [$this->app->basePath('tests')],
                static fn (string $path): bool => is_dir($path),
            ),
        );
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function catalogOnly(LanguageChecker $checker, array $report): array
    {
        $catalogue = $checker->compare();
        $errors = [];
        $warnings = [];

        foreach ($catalogue as $locale => $data) {
            foreach ($data['missing'] as $key) {
                $errors[] = sprintf('[%s] clé manquante : %s', (string) $locale, $key);
            }
            foreach ($data['empty'] as $key) {
                $errors[] = sprintf('[%s] traduction vide : %s', (string) $locale, $key);
            }
            foreach ($data['placeholders'] as $key) {
                $warnings[] = sprintf('[%s] substituts différents : %s', (string) $locale, $key);
            }
        }

        return [
            'reference' => $report['reference'],
            'locales' => $report['locales'],
            'catalogue' => $catalogue,
            'used' => 0,
            'unknown' => [],
            'unused' => [],
            'hardcoded' => [],
            'errors' => $errors,
            'warnings' => $warnings,
            'ok' => $errors === [],
        ];
    }

    /**
     * @param array<int, string> $args
     */
    private function option(array $args, string $name): ?string
    {
        $prefix = $name . '=';
        foreach ($args as $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }

    private function translator(): Translator
    {
        if ($this->app->container()->has(Translator::class)) {
            /** @var Translator $translator */
            $translator = $this->app->get(Translator::class);

            return $translator;
        }

        return new Translator(paths: [$this->app->resourcesPath('lang')]);
    }
}
