<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;
use Prime\Support\Str;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('users', static function (Blueprint $table): void {
            $table->string('username', 100)->nullable();
            $table->text('bio')->nullable();
            $table->unique('username', 'uq_users_username');
        });

        // Backfill deterministic URL-safe usernames for existing users
        $conn = $schema->connection();
        $rows = $conn->table('users')->select(['id', 'display_name', 'username'])->get();
        $used = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $existing = isset($row['username']) && is_string($row['username']) ? trim($row['username']) : '';
            if ($existing !== '') {
                $used[$existing] = true;
                continue;
            }
            $base = Str::slug((string) ($row['display_name'] ?? ''), '-', 80);
            if ($base === '') {
                $base = 'auteur-' . $id;
            }
            $candidate = $base;
            $n = 2;
            while (isset($used[$candidate])) {
                $candidate = substr($base, 0, 90) . '-' . $n;
                $n++;
            }
            $used[$candidate] = true;
            $conn->table('users')->where('id', '=', $id)->update(['username' => $candidate]);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->table('users', static function (Blueprint $table): void {
            $table->dropIndex('uq_users_username');
            $table->dropColumn(['username', 'bio']);
        });
    }
};
