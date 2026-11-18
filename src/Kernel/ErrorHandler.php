<?php

declare(strict_types=1);

namespace Prime\Kernel;

use ErrorException;
use Prime\Http\Exception\HttpException;
use Prime\Http\Exception\MethodNotAllowedException;
use Prime\Http\Middleware\SecurityHeaders;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Log\Logger;
use Prime\View\View;
use Throwable;

/**
 * Global PHP error, exception, and fatal shutdown handler.
 */
final class ErrorHandler
{
    private const FATAL_ERRORS = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
    ];

    private bool $registered = false;

    private bool $shutdownHookInstalled = false;

    private ?int $previousErrorReporting = null;

    private ?string $previousDisplayErrors = null;

    private ?string $previousLogErrors = null;

    private bool $cliExceptionHandled = false;

    /**
     * @param resource|null $cliErrorStream Optional STDERR stream override (primarily for tests)
     */
    public function __construct(
        private readonly Logger $logger,
        private View $view,
        private bool $debug = false,
        private readonly mixed $cliErrorStream = null,
        private readonly bool $terminateOnCli = true,
    ) {
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    public function setView(View $view): void
    {
        $this->view = $view;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * Register PHP error, exception, and shutdown handlers.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->previousErrorReporting = error_reporting(E_ALL);
        $previousDisplay = ini_set('display_errors', '0');
        $this->previousDisplayErrors = $previousDisplay === false ? null : $previousDisplay;

        // In CLI, PHP writes native error messages to STDERR when log_errors=1 and error_log="".
        // Disabling log_errors prevents duplicate output alongside our single formatted stderr message.
        if (PHP_SAPI === 'cli') {
            $previousLog = ini_set('log_errors', '0');
            $this->previousLogErrors = $previousLog === false ? null : $previousLog;
        }

        set_error_handler([$this, 'handlePhpError']);
        set_exception_handler([$this, 'handleUncaughtException']);

        if (!$this->shutdownHookInstalled) {
            register_shutdown_function([$this, 'handleShutdown']);
            $this->shutdownHookInstalled = true;
        }

        $this->registered = true;
    }

    /**
     * Restore previous PHP error and exception handlers.
     */
    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        restore_error_handler();
        restore_exception_handler();

        if ($this->previousErrorReporting !== null) {
            error_reporting($this->previousErrorReporting);
            $this->previousErrorReporting = null;
        }

        if ($this->previousDisplayErrors !== null) {
            ini_set('display_errors', $this->previousDisplayErrors);
            $this->previousDisplayErrors = null;
        }

        if ($this->previousLogErrors !== null) {
            ini_set('log_errors', $this->previousLogErrors);
            $this->previousLogErrors = null;
        }

        $this->registered = false;
    }

    /**
     * Convert PHP warnings/notices/errors into ErrorException instances.
     *
     * @throws ErrorException
     */
    public function handlePhpError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Format a single clean CLI error block (with stack trace when `APP_DEBUG=true`).
     */
    public function formatCliException(Throwable $exception): string
    {
        $output = sprintf(
            "[%s] %s\n  at %s:%d\n",
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        );

        if ($this->debug) {
            $trace = trim($exception->getTraceAsString());
            if ($trace !== '') {
                $output .= "\nStack trace:\n" . $trace . "\n";
            }
        }

        return $output;
    }

    /**
     * Handle uncaught exceptions reaching the top level.
     */
    public function handleUncaughtException(Throwable $exception): void
    {
        if (PHP_SAPI === 'cli') {
            if ($this->cliExceptionHandled) {
                return;
            }
            $this->cliExceptionHandled = true;

            $this->logException($exception, 500, null);
            $stream = is_resource($this->cliErrorStream) ? $this->cliErrorStream : STDERR;
            fwrite($stream, $this->formatCliException($exception));

            if ($this->terminateOnCli) {
                exit(1);
            }

            return;
        }

        $request = Request::fromGlobals();
        $response = $this->handleException($exception, $request);

        $security = new SecurityHeaders();
        $response = $security->process($request, static fn (): Response => $response);
        $response->send();
    }

    /**
     * Catch fatal errors during PHP shutdown.
     */
    public function handleShutdown(): void
    {
        if (!$this->registered || $this->cliExceptionHandled) {
            return;
        }

        $error = error_get_last();
        if ($error === null || !in_array($error['type'], self::FATAL_ERRORS, true)) {
            return;
        }

        $exception = new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line'],
        );

        $this->handleUncaughtException($exception);
    }

    /**
     * Log an exception and build the appropriate HTTP Response (HTML or JSON).
     */
    public function handleException(Throwable $exception, ?Request $request = null): Response
    {
        $status = $exception instanceof HttpException ? $exception->statusCode() : 500;
        $headers = $exception instanceof HttpException ? $exception->headers() : [];

        $this->logException($exception, $status, $request);

        if ($request !== null && $request->wantsJson()) {
            return $this->renderJson($exception, $status, $headers);
        }

        return $this->renderHtml($exception, $status, $headers, $request);
    }

    /**
     * Extract surrounding source lines for the development error page.
     *
     * @return list<array{number: int, code: string, highlight: bool}>
     */
    public function extractCodeExcerpt(string $file, int $line, int $radius = 8): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $total = count($lines);
        $start = max(1, $line - $radius);
        $end = min($total, $line + $radius);

        $excerpt = [];
        for ($current = $start; $current <= $end; $current++) {
            $excerpt[] = [
                'number' => $current,
                'code' => $lines[$current - 1],
                'highlight' => $current === $line,
            ];
        }

        return $excerpt;
    }

    private function logException(Throwable $exception, int $status, ?Request $request): void
    {
        $context = [
            'status' => $status,
            'exception' => $exception,
        ];

        if ($request !== null) {
            $context['method'] = $request->method();
            $context['path'] = $request->path();
        }

        try {
            if ($status >= 500) {
                $context['trace'] = $exception->getTraceAsString();
                $this->logger->error(
                    sprintf('Unhandled %s: %s', $exception::class, $exception->getMessage()),
                    $context,
                );
            } else {
                $this->logger->notice(
                    sprintf('HTTP %d: %s', $status, $exception->getMessage()),
                    $context,
                );
            }
        } catch (Throwable) {
            // Avoid infinite recursion if logging itself fails.
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function renderJson(Throwable $exception, int $status, array $headers): Response
    {
        $error = [
            'code' => $this->errorCode($status),
            'message' => $this->publicMessage($exception, $status),
            'status' => $status,
        ];

        if ($this->debug && $status >= 500) {
            $error['message'] = $exception->getMessage();
            $error['exception'] = $exception::class;
            $error['file'] = $exception->getFile();
            $error['line'] = $exception->getLine();
        }

        return Response::json(['error' => $error], $status, $headers);
    }

    /**
     * @return array<int, string>
     */
    private function errorCodes(): array
    {
        return [
            400 => 'invalid_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            419 => 'csrf_invalid',
            429 => 'too_many_requests',
            500 => 'internal_error',
        ];
    }

    private function errorCode(int $status): string
    {
        $codes = $this->errorCodes();

        return $codes[$status] ?? 'error';
    }

    /**
     * @param array<string, string> $headers
     */
    private function renderHtml(Throwable $exception, int $status, array $headers, ?Request $request): Response
    {
        try {
            if ($status === 403) {
                $html = $this->view->render('errors.403', [
                    'path' => $request?->path() ?? '/admin',
                    'message' => $this->publicMessage($exception, 403),
                ]);

                return Response::html($html, 403, $headers);
            }

            if ($status === 404) {
                $html = $this->view->render('errors.404', [
                    'path' => $request?->path() ?? '',
                ]);

                return Response::html($html, 404, $headers);
            }

            if ($status === 405) {
                $allowed = $exception instanceof MethodNotAllowedException
                    ? $exception->allowedMethods()
                    : [];

                $html = $this->view->render('errors.405', [
                    'method' => $request?->method() ?? '',
                    'path' => $request?->path() ?? '',
                    'allowedMethods' => $allowed,
                ]);

                return Response::html($html, 405, $headers);
            }

            if ($status === 419) {
                $html = $this->view->render('errors.419', [
                    'path' => $request?->path() ?? '/',
                ]);

                return Response::html($html, 419, $headers);
            }

            if ($this->debug) {
                $html = $this->view->render('errors.debug', [
                    'status' => $status,
                    'exceptionClass' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTrace(),
                    'traceString' => $exception->getTraceAsString(),
                    'excerpt' => $this->extractCodeExcerpt($exception->getFile(), $exception->getLine()),
                    'requestMethod' => $request?->method() ?? 'CLI',
                    'requestPath' => $request?->path() ?? '/',
                ]);

                return Response::html($html, $status, $headers);
            }

            $html = $this->view->render('errors.500', [
                'status' => $status,
            ]);

            return Response::html($html, $status, $headers);
        } catch (Throwable) {
            $safeTitle = $status === 404 ? '404 — Page introuvable' : sprintf('%d — Erreur serveur', $status);
            $fallback = sprintf(
                '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>%s</title></head><body><h1>%s</h1></body></html>',
                htmlspecialchars($safeTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($safeTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );

            return Response::html($fallback, $status, $headers);
        }
    }

    private function publicMessage(Throwable $exception, int $status): string
    {
        if ($status === 403) {
            return $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Vous n\'avez pas la permission d\'accéder à cette ressource.';
        }

        if ($status === 404) {
            return 'Page introuvable.';
        }

        if ($status === 405) {
            return 'Méthode non autorisée.';
        }

        if ($status === 419) {
            return 'Jeton CSRF invalide ou session expirée.';
        }

        if ($status === 429) {
            return $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Trop de tentatives. Veuillez réessayer ultérieurement.';
        }

        if ($status >= 500) {
            return 'Erreur interne du serveur.';
        }

        return $exception->getMessage() !== '' ? $exception->getMessage() : 'Requête invalide.';
    }
}
