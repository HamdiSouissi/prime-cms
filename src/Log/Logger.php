<?php

declare(strict_types=1);

namespace Prime\Log;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Stringable;
use Throwable;

/**
 * Daily-rotated single-line file logger with severity filtering and `{placeholder}` interpolation.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const NOTICE = 'notice';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';
    public const ALERT = 'alert';
    public const EMERGENCY = 'emergency';

    /**
     * Severity ranking from lowest to highest.
     *
     * @var array<string, int>
     */
    private const LEVELS = [
        self::DEBUG => 0,
        self::INFO => 1,
        self::NOTICE => 2,
        self::WARNING => 3,
        self::ERROR => 4,
        self::CRITICAL => 5,
        self::ALERT => 6,
        self::EMERGENCY => 7,
    ];

    private readonly string $directory;

    private readonly string $minLevelName;

    private readonly int $minSeverity;

    private readonly string $prefix;

    public function __construct(
        string $directory,
        string $minLevel = self::DEBUG,
        string $prefix = 'prime',
    ) {
        $normalizedLevel = strtolower(trim($minLevel));
        if (!isset(self::LEVELS[$normalizedLevel])) {
            throw new InvalidArgumentException(sprintf('Unknown log level [%s].', $minLevel));
        }

        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR . '/');
        $this->minLevelName = $normalizedLevel;
        $this->minSeverity = self::LEVELS[$normalizedLevel];
        $this->prefix = $prefix !== '' ? $prefix : 'prime';
    }

    /**
     * Create a child logger writing to the same directory with a dedicated channel prefix
     * (e.g. `plugin.hello-prime` -> `storage/logs/plugin.hello-prime-YYYY-MM-DD.log`).
     */
    public function withChannel(string $channel): self
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '-', trim($channel));
        $prefix = is_string($sanitized) && $sanitized !== '' ? $sanitized : $this->prefix;

        return new self($this->directory, $this->minLevelName, $prefix);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /**
     * Record a log entry if its severity meets or exceeds the configured threshold.
     *
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $normalizedLevel = strtolower(trim($level));
        if (!isset(self::LEVELS[$normalizedLevel])) {
            throw new InvalidArgumentException(sprintf('Unknown log level [%s].', $level));
        }

        if (self::LEVELS[$normalizedLevel] < $this->minSeverity) {
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $interpolated = $this->sanitizeSingleLine($this->interpolate($message, $context));
        $normalizedContext = $this->normalizeContext($context);

        $jsonContext = json_encode(
            $normalizedContext === [] ? new stdClass() : $normalizedContext,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        $line = sprintf(
            '[%s] %s: %s %s%s',
            $now->format(DateTimeInterface::ATOM),
            strtoupper($normalizedLevel),
            $interpolated,
            $jsonContext === false ? '{}' : $jsonContext,
            PHP_EOL,
        );

        $this->ensureDirectoryExists();
        $file = $this->logFilePath($now);

        if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write log file [%s].', $file));
        }
    }

    /**
     * Return the daily log file path for a given UTC date (today by default).
     */
    public function logFilePath(?DateTimeImmutable $date = null): string
    {
        $date ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return sprintf('%s%s%s-%s.log', $this->directory, DIRECTORY_SEPARATOR, $this->prefix, $date->format('Y-m-d'));
    }

    /**
     * Return the directory where log files are stored.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Replace `{key}` placeholders in the message using scalar or Stringable context values.
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if ($context === [] || !str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_bool($value)) {
                $replacements['{' . $key . '}'] = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $replacements['{' . $key . '}'] = 'null';
            } elseif (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * Normalize context values for safe single-line JSON serialization.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->normalizeValue($v);
            }

            return $result;
        }

        if (is_object($value)) {
            if ($value instanceof Stringable) {
                return (string) $value;
            }

            return '[object ' . $value::class . ']';
        }

        if (is_resource($value)) {
            return '[resource ' . get_resource_type($value) . ']';
        }

        return $value;
    }

    private function sanitizeSingleLine(string $text): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $text));
    }

    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                throw new RuntimeException(sprintf('Unable to create log directory [%s].', $this->directory));
            }
        }
    }
}
