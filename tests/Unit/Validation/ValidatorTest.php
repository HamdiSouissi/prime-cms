<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Validation;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Validation\Validator;

final class ValidatorTest extends TestCase
{
    public function testCoreValidationRulesAndFrenchMessages(): void
    {
        $validator = Validator::make(
            data: [
                'name' => 'A',
                'email' => 'invalid-email',
                'age' => 'not-an-int',
                'role' => 'unknown',
                'slug' => 'Bad Slug!',
                'active' => 'maybe',
                'password' => 'short',
                'password_confirmation' => 'different',
            ],
            rules: [
                'name' => 'required|string|between:2,50',
                'email' => 'required|email',
                'age' => 'required|integer|min:18|max:99',
                'role' => 'required|in:editor,author',
                'slug' => ['required', 'string', 'regex:/^[a-z0-9-]+$/'],
                'active' => 'required|boolean',
                'password' => 'required|confirmed|password',
            ],
            labels: [
                'name' => 'Nom',
                'slug' => 'Identifiant',
            ],
        );

        $this->assertTrue($validator->fails());
        $errors = $validator->errors();
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
        $this->assertArrayHasKey('role', $errors);
        $this->assertArrayHasKey('slug', $errors);
        $this->assertArrayHasKey('active', $errors);
        $this->assertArrayHasKey('password', $errors);
        $this->assertStringContainsString('Nom', $errors['name']);
    }

    public function testUniqueExistsNullableAndPassingData(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY, email VARCHAR(191))');
        $pdo->exec("INSERT INTO pc_users (id, email) VALUES (1, 'existing@example.fr')");

        $conn = new Connection(['prefix' => 'pc_'], pdo: $pdo);

        // Duplicate email rejected
        $dup = Validator::make(
            ['email' => 'existing@example.fr', 'user_id' => 999],
            ['email' => 'required|email|unique:users,email', 'user_id' => 'exists:users,id'],
            connection: $conn,
        );
        $this->assertTrue($dup->fails());
        $this->assertArrayHasKey('email', $dup->errors());
        $this->assertArrayHasKey('user_id', $dup->errors());

        // Ignore own ID passes + nullable empty field passes
        $ok = Validator::make(
            ['email' => 'existing@example.fr', 'user_id' => 1, 'bio' => ''],
            [
                'email' => 'required|email|unique:users,email,1',
                'user_id' => 'required|integer|exists:users,id',
                'bio' => 'nullable|string|max:200',
            ],
            connection: $conn,
        );
        $this->assertTrue($ok->passes());
        $this->assertSame([], $ok->errors());
    }
}
