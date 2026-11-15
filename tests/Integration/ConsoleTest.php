<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Hooks\Hooks;
use Prime\Kernel\Application;

final class ConsoleTest extends TestCase
{
    public function testBuiltInCommandsExecuteCleanly(): void
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->boot();

        $console = new ConsoleApplication($app);

        $listOut = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'list'], $listOut));
        $this->assertStringContainsString('about', $listOut->stdout());
        $this->assertStringContainsString('routes', $listOut->stdout());

        $aboutOut = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'about'], $aboutOut));
        $this->assertStringContainsString(Application::VERSION, $aboutOut->stdout());
        $this->assertStringContainsString(PHP_VERSION, $aboutOut->stdout());

        $routesOut = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'routes'], $routesOut));
        $this->assertStringContainsString('/health', $routesOut->stdout());
        $this->assertStringContainsString('home', $routesOut->stdout());
    }

    public function testUnknownCommandReturnsFailureStatus(): void
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->boot();

        $console = new ConsoleApplication($app);
        $out = new ConsoleOutput(buffered: true);

        $this->assertSame(1, $console->run(['bin/prime', 'unknown:cmd'], $out));
        $this->assertStringContainsString('Commande inconnue', $out->stderr());
    }

    public function testConsoleCommandsHookRegistersCustomCommands(): void
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->boot();

        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);
        $hooks->on('console.commands', static function (ConsoleApplication $cli): void {
            $cli->register(new class () implements CommandInterface {
                public function name(): string
                {
                    return 'ping';
                }

                public function description(): string
                {
                    return 'Custom ping command';
                }

                public function execute(array $args, ConsoleOutput $output): int
                {
                    $output->writeln('pong:' . ($args[0] ?? 'ok'));

                    return self::SUCCESS;
                }
            });
        });

        $console = new ConsoleApplication($app);
        $out = new ConsoleOutput(buffered: true);

        $this->assertSame(0, $console->run(['bin/prime', 'ping', 'prime'], $out));
        $this->assertSame('pong:prime' . PHP_EOL, $out->stdout());
    }
}
