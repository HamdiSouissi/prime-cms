<?php

declare(strict_types=1);

namespace Prime\Console;

/**
 * Contract for CLI commands executable through `bin/prime`.
 */
interface CommandInterface
{
    public const SUCCESS = 0;
    public const FAILURE = 1;

    /**
     * Unique command identifier (e.g. `about`, `routes`, `migrate`).
     */
    public function name(): string;

    /**
     * Short human-readable description shown in `bin/prime list`.
     */
    public function description(): string;

    /**
     * Execute the command and return a POSIX exit status code.
     *
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int;
}
