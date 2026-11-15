<?php

declare(strict_types=1);

namespace Prime\Console;

/**
 * Output writer for CLI commands supporting real stdio streams and buffered testing.
 */
final class ConsoleOutput
{
    private string $stdoutBuffer = '';

    private string $stderrBuffer = '';

    public function __construct(private readonly bool $buffered = false)
    {
    }

    public function writeln(string $line = ''): void
    {
        $text = $line . PHP_EOL;

        if ($this->buffered) {
            $this->stdoutBuffer .= $text;

            return;
        }

        fwrite(STDOUT, $text);
    }

    public function error(string $line): void
    {
        $text = $line . PHP_EOL;

        if ($this->buffered) {
            $this->stderrBuffer .= $text;

            return;
        }

        fwrite(STDERR, $text);
    }

    /**
     * Render a clean padded ASCII table (UTF-8 aware).
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = [];
        foreach ($headers as $index => $header) {
            $widths[$index] = mb_strlen($header, 'UTF-8');
        }

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $len = mb_strlen($cell, 'UTF-8');
                $widths[$index] = max($widths[$index] ?? 0, $len);
            }
        }

        $separator = $this->buildSeparator($widths);

        $this->writeln($separator);
        $this->writeln($this->buildRow($headers, $widths));
        $this->writeln($separator);

        foreach ($rows as $row) {
            $this->writeln($this->buildRow($row, $widths));
        }

        $this->writeln($separator);
    }

    public function stdout(): string
    {
        return $this->stdoutBuffer;
    }

    public function stderr(): string
    {
        return $this->stderrBuffer;
    }

    /**
     * @param array<int, int> $widths
     */
    private function buildSeparator(array $widths): string
    {
        $parts = [];
        foreach ($widths as $width) {
            $parts[] = str_repeat('-', $width + 2);
        }

        return '+' . implode('+', $parts) . '+';
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths
     */
    private function buildRow(array $cells, array $widths): string
    {
        $parts = [];
        foreach ($widths as $index => $width) {
            $value = $cells[$index] ?? '';
            $pad = max(0, $width - mb_strlen($value, 'UTF-8'));
            $parts[] = ' ' . $value . str_repeat(' ', $pad) . ' ';
        }

        return '|' . implode('|', $parts) . '|';
    }
}
