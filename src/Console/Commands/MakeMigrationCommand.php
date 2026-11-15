<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Kernel\Application;

/**
 * Generates a timestamped PSR-12 migration file inside `database/migrations/`.
 */
final class MakeMigrationCommand implements CommandInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly ?string $targetDirectory = null,
    ) {
    }

    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'Crée un nouveau fichier de migration horodaté dans database/migrations/';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $rawName = $parsed['positionals'][0] ?? '';

        $slug = strtolower(trim($rawName));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        if ($slug === '' || preg_match('/^[a-z][a-z0-9_]*$/', $slug) !== 1) {
            $output->error('Usage : php bin/prime make:migration <nom_en_snake_case>');
            $output->error('Exemple : php bin/prime make:migration create_entries_table');

            return self::FAILURE;
        }

        $dir = $this->targetDirectory ?? $this->app->basePath('database/migrations');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $output->error(sprintf('Impossible de créer le dossier [%s].', $dir));

            return self::FAILURE;
        }

        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y_m_d_His');
        $filename = $timestamp . '_' . $slug . '.php';
        $fullPath = rtrim($dir, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $filename;

        $template = $this->stub();
        if (@file_put_contents($fullPath, $template) === false) {
            $output->error(sprintf('Impossible d\'écrire le fichier de migration [%s].', $fullPath));

            return self::FAILURE;
        }

        $output->writeln('Migration créée : ' . $fullPath);

        return self::SUCCESS;
    }

    private function stub(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        // Exemple :
        // $schema->create('example', static function (Blueprint $table): void {
        //     $table->id();
        //     $table->timestamps();
        // });
    }

    public function down(Schema $schema): void
    {
        // $schema->dropIfExists('example');
    }
};

PHP;
    }
}
