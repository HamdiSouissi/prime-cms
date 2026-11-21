<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Api\Middleware\ApiCors;
use Prime\Database\Connection;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;

/**
 * Regression (phase 12): the global CORS middleware read the settings on every request that
 * carried an `Origin` header — every form post in a browser. Before installation there is no
 * settings table, so each step of the web installer answered 500.
 */
final class ApiCorsScopeTest extends TestCase
{
    public function testFormPostsOutsideTheApiNeverReadTheSettings(): void
    {
        $cors = new ApiCors(new Settings($this->unusableConnection()));

        foreach (['/install', '/install/database', '/admin/login', '/'] as $path) {
            $request = Request::create('POST', $path, headers: ['Origin' => 'http://localhost:8080']);
            $response = $cors->process($request, static fn (): Response => Response::html('ok'));

            $this->assertSame('ok', $response->body(), $path);
            $this->assertNull($response->header('Access-Control-Allow-Origin'));
        }
    }

    public function testApiRequestsStillGetTheirCorsHeaders(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE pc_settings (name VARCHAR(191) PRIMARY KEY, value TEXT, autoload INTEGER DEFAULT 1, updated_at DATETIME)');
        $pdo->exec("INSERT INTO pc_settings (name, value, autoload) VALUES ('api.cors_origins', '\"https://app.example\"', 1)");
        $cors = new ApiCors(new Settings(new Connection(['prefix' => 'pc_'], pdo: $pdo)));

        $response = $cors->process(
            Request::create('GET', '/api/v1/site', headers: ['Origin' => 'https://app.example']),
            static fn (): Response => Response::json(['ok' => true]),
        );

        $this->assertSame('https://app.example', $response->header('Access-Control-Allow-Origin'));
    }

    private function unusableConnection(): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // No settings table: any read throws, like a database that is not installed yet.
        return new Connection(['prefix' => 'pc_'], pdo: $pdo);
    }
}
