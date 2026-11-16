<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Media\MediaConfig;
use Prime\Media\MediaHelper;
use Prime\Media\MediaRepository;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Scan `content/uploads/` and remove orphan files not referenced by any `media` database row (`bin/prime media:cleanup`).
 */
final class MediaCleanupCommand implements CommandInterface
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaConfig $config,
    ) {
    }

    public function name(): string
    {
        return 'media:cleanup';
    }

    public function description(): string
    {
        return 'Supprime les fichiers orphelins de content/uploads/ non référencés en base (--dry-run)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['dry-run']);
        $dryRun = ArgParser::hasFlag($parsed['options'], 'dry-run');

        $root = $this->config->uploadsAbsolutePath();
        if (!is_dir($root)) {
            $output->writeln('Le dossier content/uploads/ est introuvable ou vide.');

            return self::SUCCESS;
        }

        // Build fast lookup set of every active original & variant relative path in DB
        $known = [];
        foreach ($this->media->all() as $item) {
            foreach ($item->allRelativePaths() as $rel) {
                $known[ltrim(str_replace('\\', '/', $rel), '/')] = true;
            }
        }

        $orphans = [];
        $totalBytes = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $baseName = $fileInfo->getFilename();
            // Always preserve `.htaccess` and hidden security files (`.gitkeep`, etc.)
            if (str_starts_with($baseName, '.')) {
                continue;
            }

            if ($fileInfo->isDir()) {
                continue;
            }

            $abs = str_replace('\\', '/', $fileInfo->getPathname());
            if (!str_starts_with($abs, $normalizedRoot)) {
                continue;
            }
            $rel = ltrim(substr($abs, strlen($normalizedRoot)), '/');

            if (!isset($known[$rel])) {
                $size = $fileInfo->isFile() ? (int) $fileInfo->getSize() : 0;
                $orphans[] = ['rel' => $rel, 'abs' => $fileInfo->getPathname(), 'size' => $size];
                $totalBytes += $size;
            }
        }

        if ($orphans === []) {
            $output->writeln('Aucun fichier orphelin détecté dans content/uploads/.');

            return self::SUCCESS;
        }

        $removed = 0;
        foreach ($orphans as $orphan) {
            $prefix = $dryRun ? '[dry-run] Orphelin : ' : 'Supprimé : ';
            $output->writeln(sprintf('%s%s (%s)', $prefix, $orphan['rel'], MediaHelper::formatBytes($orphan['size'])));
            if (!$dryRun && is_file($orphan['abs']) && @unlink($orphan['abs'])) {
                $removed++;
            }
        }

        if ($dryRun) {
            $output->writeln(sprintf(
                'Simulation (--dry-run) : %d fichier(s) orphelin(s) détecté(s) pour %s. Relancez sans --dry-run pour supprimer.',
                count($orphans),
                MediaHelper::formatBytes($totalBytes),
            ));
        } else {
            $output->writeln(sprintf(
                'Nettoyage terminé : %d/%d fichier(s) supprimé(s), %s libéré(s).',
                $removed,
                count($orphans),
                MediaHelper::formatBytes($totalBytes),
            ));
        }

        return self::SUCCESS;
    }
}
