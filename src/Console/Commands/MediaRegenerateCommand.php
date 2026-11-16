<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Media\Media;
use Prime\Media\MediaConfig;
use Prime\Media\MediaRepository;
use Prime\Media\UploadService;
use Throwable;

/**
 * Regenerate GD image variants (JPEG/PNG/GIF + WebP) (`bin/prime media:regenerate`).
 */
final class MediaRegenerateCommand implements CommandInterface
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly UploadService $uploader,
        private readonly MediaConfig $config,
    ) {
    }

    public function name(): string
    {
        return 'media:regenerate';
    }

    public function description(): string
    {
        return 'Régénère les variantes d\'images (miniatures + WebP) (--id=N --variant=nom --force)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['force']);
        $idRaw = ArgParser::stringOption($parsed['options'], 'id');
        $variant = trim(ArgParser::stringOption($parsed['options'], 'variant'));
        $force = ArgParser::hasFlag($parsed['options'], 'force');

        $sizes = $this->config->imageSizes();
        if ($variant !== '' && !array_key_exists($variant, $sizes)) {
            $output->error(sprintf(
                'Variante inconnue « %s ». Variantes configurées : %s.',
                $variant,
                implode(', ', array_keys($sizes)),
            ));

            return self::FAILURE;
        }

        $targets = [];
        if ($idRaw !== '') {
            if (!ctype_digit($idRaw) || (int) $idRaw <= 0) {
                $output->error('L\'option --id doit être un entier positif.');

                return self::FAILURE;
            }
            $item = $this->media->find((int) $idRaw);
            if ($item === null) {
                $output->error(sprintf('Média #%d introuvable.', (int) $idRaw));

                return self::FAILURE;
            }
            $targets[] = $item;
        } else {
            $targets = $this->media->all(Media::KIND_IMAGE);
        }

        if ($targets === []) {
            $output->writeln('Aucune image à traiter dans la médiathèque.');

            return self::SUCCESS;
        }

        $uploadsRoot = rtrim($this->config->uploadsAbsolutePath(), '/\\');
        $regenerated = 0;
        $skipped = 0;
        $failed = 0;
        $total = count($targets);

        foreach ($targets as $idx => $item) {
            $num = $idx + 1;
            if (!$item->isImage()) {
                $skipped++;
                continue;
            }

            if (!$force && $variant !== '' && isset($item->variants[$variant])) {
                $vPath = (string) ($item->variants[$variant]['path'] ?? '');
                if ($vPath !== '' && is_file($uploadsRoot . '/' . ltrim($vPath, '/'))) {
                    $skipped++;
                    continue;
                }
            }

            try {
                $this->uploader->regenerate($item, $variant !== '' ? $variant : null);
                $regenerated++;
                $output->writeln(sprintf('[%d/%d] #%d %s — variantes régénérées', $num, $total, $item->id, $item->originalFilename));
            } catch (Throwable $exception) {
                $failed++;
                $output->error(sprintf('[%d/%d] #%d %s — échec : %s', $num, $total, $item->id, $item->originalFilename, $exception->getMessage()));
            }
        }

        $output->writeln(sprintf(
            'Terminé : %d régénérée(s), %d ignorée(s), %d erreur(s).',
            $regenerated,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
