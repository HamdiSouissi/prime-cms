<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Media\UploadsGuard;

/**
 * Create or verify the `public/uploads -> ../content/uploads` symlink and `.htaccess` guards (`bin/prime media:link`).
 */
final class MediaLinkCommand implements CommandInterface
{
    public function __construct(private readonly UploadsGuard $guard)
    {
    }

    public function name(): string
    {
        return 'media:link';
    }

    public function description(): string
    {
        return 'Crée ou vérifie le lien symbolique public/uploads → ../content/uploads et la protection .htaccess';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $this->guard->ensureProtectedUploadsDirectory();
        $result = $this->guard->createPublicSymlink();

        if ($result['status'] === 'created' || $result['status'] === 'already_linked') {
            $output->writeln($result['message']);
            $output->writeln('Protection .htaccess vérifiée dans content/uploads/.htaccess.');

            return self::SUCCESS;
        }

        $output->error($result['message']);
        $output->writeln('Le fallback sécurisé PHP (GET /uploads/{path}) reste actif.');

        return self::FAILURE;
    }
}
