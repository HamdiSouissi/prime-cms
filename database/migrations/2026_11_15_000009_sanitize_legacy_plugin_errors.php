<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Schema;
use Prime\Plugins\PluginManager;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('plugins')) {
            return;
        }

        $connection = $schema->connection();
        $rows = $connection->table('plugins')
            ->select(['slug', 'last_error'])
            ->whereNotNull('last_error')
            ->get();

        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            $rawError = (string) ($row['last_error'] ?? '');

            if ($slug === '' || $rawError === '') {
                continue;
            }

            $sanitized = PluginManager::sanitizeStoredErrorMessage($slug, $rawError);
            if ($sanitized !== $rawError) {
                $connection->table('plugins')
                    ->where('slug', '=', $slug)
                    ->update(['last_error' => $sanitized]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Data sanitization migration is intentionally irreversible for security.
    }
};
