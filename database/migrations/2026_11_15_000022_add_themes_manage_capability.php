<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $connection = $schema->connection();

        // Grant `themes.manage` to any non-wildcard role that already held `appearance.manage`.
        // Note: `administrator` holds `*`, which grants `themes.manage` automatically.
        $rows = $connection->table('role_capabilities')
            ->select(['role_id'])
            ->where('capability', '=', 'appearance.manage')
            ->get();

        foreach ($rows as $row) {
            $roleId = (int) $row['role_id'];
            $exists = $connection->table('role_capabilities')
                ->where('role_id', '=', $roleId)
                ->where('capability', '=', 'themes.manage')
                ->exists();

            if (!$exists) {
                $connection->table('role_capabilities')->insert([
                    'role_id' => $roleId,
                    'capability' => 'themes.manage',
                ]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $schema->connection()
            ->table('role_capabilities')
            ->where('capability', '=', 'themes.manage')
            ->delete();
    }
};
