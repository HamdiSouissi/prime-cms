<?php

declare(strict_types=1);

namespace Prime\Http\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Kernel\Config;
use Prime\View\View;

/**
 * Minimal Phase 1 controller serving the temporary homepage and `/health` endpoint.
 */
final class HomeController
{
    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly View $view,
    ) {
    }

    /**
     * Render the temporary brand homepage (`GET /`).
     */
    public function index(Request $request): Response
    {
        $debug = $this->app->isDebug();
        $data = [
            'appName' => (string) $this->config->get('app.name', 'Prime CMS'),
            'version' => Application::VERSION,
            'debug' => $debug,
        ];

        if ($debug) {
            $storageDirs = [
                'storage/cache' => $this->app->storagePath('cache'),
                'storage/logs' => $this->app->storagePath('logs'),
                'storage/sessions' => $this->app->storagePath('sessions'),
                'storage/tmp' => $this->app->storagePath('tmp'),
            ];

            $storageStatus = [];
            $allWritable = true;

            foreach ($storageDirs as $label => $fullPath) {
                $writable = is_dir($fullPath) && is_writable($fullPath);
                $storageStatus[$label] = $writable;
                if (!$writable) {
                    $allWritable = false;
                }
            }

            $data['phpVersion'] = PHP_VERSION;
            $data['environment'] = $this->app->environment();
            $data['storageStatus'] = $storageStatus;
            $data['allWritable'] = $allWritable;
        }

        $html = $this->view->render('home', $data);

        return Response::html($html);
    }

    /**
     * Return JSON healthcheck payload (`GET /health`).
     */
    public function health(Request $request): Response
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $payload = [
            'status' => 'ok',
            'app' => (string) $this->config->get('app.name', 'Prime CMS'),
            'version' => Application::VERSION,
        ];

        if ($this->app->isDebug()) {
            $payload['php'] = PHP_VERSION;
        }

        $payload['time'] = $now;

        return Response::json($payload);
    }
}
