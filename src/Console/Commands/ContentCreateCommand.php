<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Content\Entry;
use Prime\Content\EntryService;
use Prime\Content\Exception\ContentValidationException;
use Throwable;

/**
 * Creates a content entry from the CLI (`bin/prime content:create`).
 */
final class ContentCreateCommand implements CommandInterface
{
    public function __construct(
        private readonly EntryService $service,
        private readonly UserRepository $users,
    ) {
    }

    public function name(): string
    {
        return 'content:create';
    }

    public function description(): string
    {
        return 'Crée un contenu (--type, --title, --status, --slug, --body, --author, --publish-at)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $opts = $parsed['options'];

        $type = ArgParser::stringOption($opts, 'type', 'post');
        $title = ArgParser::stringOption($opts, 'title');
        $slug = ArgParser::stringOption($opts, 'slug');
        $status = ArgParser::stringOption($opts, 'status', Entry::STATUS_DRAFT);
        $body = ArgParser::stringOption($opts, 'body');
        $authorSpec = ArgParser::stringOption($opts, 'author');
        $publishAt = ArgParser::stringOption($opts, 'publish-at');

        if (trim($title) === '') {
            $output->error('L’option --title est obligatoire.');

            return self::FAILURE;
        }

        $actor = $this->resolveActor($authorSpec !== '' ? $authorSpec : null);
        if ($actor === null) {
            $output->error('Aucun utilisateur administrateur/auteur valide trouvé pour créer le contenu.');

            return self::FAILURE;
        }

        $contentJson = $this->normalizeBody($body);

        try {
            $entry = $this->service->create($type, [
                'title' => $title,
                'slug' => $slug,
                'status' => $status,
                'content' => $contentJson,
                'published_at' => $publishAt !== '' ? $publishAt : null,
            ], $actor);
        } catch (ContentValidationException $exception) {
            $output->error('Échec de validation :');
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    $output->error(sprintf('  - %s : %s', $field, $msg));
                }
            }

            return self::FAILURE;
        } catch (Throwable $exception) {
            $output->error('Impossible de créer le contenu : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'Contenu #%d (%s) créé : « %s » [slug: %s, statut: %s]',
            $entry->id,
            $entry->type,
            $entry->title,
            $entry->slug,
            $entry->status,
        ));

        return self::SUCCESS;
    }

    private function resolveActor(?string $authorSpec): ?User
    {
        if ($authorSpec !== null && $authorSpec !== '') {
            if (ctype_digit($authorSpec)) {
                return $this->users->find((int) $authorSpec);
            }

            return $this->users->findByEmail($authorSpec);
        }

        $list = $this->users->paginate(page: 1, perPage: 20)['items'];
        foreach ($list as $user) {
            if ($user->isAdministrator()) {
                return $user;
            }
        }

        return $list[0] ?? null;
    }

    private function normalizeBody(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '{"version":1,"blocks":[{"id":"b1","type":"paragraph","data":{"text":""}}]}';
        }

        // Accept raw Prime Editor JSON directly if passed
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
                return $trimmed;
            }
        }

        $paragraphs = preg_split('/\R{2,}/', $trimmed) ?: [$trimmed];
        $blocks = [];
        foreach ($paragraphs as $idx => $chunk) {
            $line = trim($chunk);
            if ($line === '') {
                continue;
            }
            $blocks[] = [
                'id' => 'b' . ($idx + 1),
                'type' => 'paragraph',
                'data' => [
                    'text' => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                ],
            ];
        }

        $encoded = json_encode(['version' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{"version":1,"blocks":[]}';
    }
}
