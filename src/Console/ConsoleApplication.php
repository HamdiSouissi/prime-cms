<?php

declare(strict_types=1);

namespace Prime\Console;

use Prime\Api\Repository\ApiTokenRepository;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\RoleRepository;
use Prime\Auth\UserGuard;
use Prime\Auth\UserRepository;
use Prime\Cache\CacheManager;
use Prime\Console\Commands\AboutCommand;
use Prime\Console\Commands\ApiTokenCreateCommand;
use Prime\Console\Commands\ApiTokenListCommand;
use Prime\Console\Commands\ApiTokenRevokeCommand;
use Prime\Console\Commands\AssetsBuildCommand;
use Prime\Console\Commands\CacheClearCommand;
use Prime\Console\Commands\ContentCreateCommand;
use Prime\Console\Commands\ContentListCommand;
use Prime\Console\Commands\ContentPublishScheduledCommand;
use Prime\Console\Commands\ContentPurgeTrashCommand;
use Prime\Console\Commands\ContentRerenderCommand;
use Prime\Console\Commands\ContentTypesCommand;
use Prime\Console\Commands\DbCheckCommand;
use Prime\Console\Commands\InstallCommand;
use Prime\Console\Commands\LangCheckCommand;
use Prime\Console\Commands\ListCommand;
use Prime\Console\Commands\MailLogsCommand;
use Prime\Console\Commands\MailPruneCommand;
use Prime\Console\Commands\MailTestCommand;
use Prime\Console\Commands\MakeMigrationCommand;
use Prime\Console\Commands\MakePluginCommand;
use Prime\Console\Commands\MediaCleanupCommand;
use Prime\Console\Commands\MediaLinkCommand;
use Prime\Console\Commands\MediaRegenerateCommand;
use Prime\Console\Commands\MigrateCommand;
use Prime\Console\Commands\MigrateFreshCommand;
use Prime\Console\Commands\MigrateResetCommand;
use Prime\Console\Commands\MigrateRollbackCommand;
use Prime\Console\Commands\MigrateStatusCommand;
use Prime\Console\Commands\PluginActivateCommand;
use Prime\Console\Commands\PluginDeactivateCommand;
use Prime\Console\Commands\PluginInstallCommand;
use Prime\Console\Commands\PluginListCommand;
use Prime\Console\Commands\PluginPublishAssetsCommand;
use Prime\Console\Commands\PluginUninstallCommand;
use Prime\Console\Commands\QueueRetryCommand;
use Prime\Console\Commands\QueueStatusCommand;
use Prime\Console\Commands\QueueWorkCommand;
use Prime\Console\Commands\RoleListCommand;
use Prime\Console\Commands\RoutesCommand;
use Prime\Console\Commands\ScheduleRunCommand;
use Prime\Console\Commands\SettingsGetCommand;
use Prime\Console\Commands\SettingsListCommand;
use Prime\Console\Commands\SettingsSetCommand;
use Prime\Console\Commands\ThemeActivateCommand;
use Prime\Console\Commands\ThemeListCommand;
use Prime\Console\Commands\ThemePublishCommand;
use Prime\Console\Commands\UserCreateCommand;
use Prime\Console\Commands\UserDisableCommand;
use Prime\Console\Commands\UserEnableCommand;
use Prime\Console\Commands\UserListCommand;
use Prime\Console\Commands\UserPasswordCommand;
use Prime\Console\Commands\UserRoleCommand;
use Prime\Console\Commands\UserTwoFactorResetCommand;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryService;
use Prime\Content\TaxonomyRegistry;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Install\Installer;
use Prime\Jobs\JobQueue;
use Prime\Jobs\JobRepository;
use Prime\Kernel\Application;
use Prime\Mail\Mailer;
use Prime\Media\MediaConfig;
use Prime\Media\MediaRepository;
use Prime\Media\UploadService;
use Prime\Media\UploadsGuard;
use Prime\Plugins\PluginInstaller;
use Prime\Plugins\PluginManager;
use Prime\Scheduler\Schedule;
use Prime\Scheduler\SchedulerRunner;
use Prime\Security\PasswordPolicy;
use Prime\Settings\Settings;
use Prime\Settings\SettingsRegistry;
use Prime\Themes\ThemeManager;

/**
 * Extensible CLI runner for Prime CMS (`bin/prime`).
 */
final class ConsoleApplication
{
    /**
     * @var array<string, CommandInterface>
     */
    private array $commands = [];

    private bool $booted = false;

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Register a command (can be called by plugins via the `console.commands` hook).
     */
    public function register(CommandInterface $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    /**
     * Return all registered commands sorted alphabetically by name.
     *
     * @return array<string, CommandInterface>
     */
    public function commands(): array
    {
        $this->bootCommands();
        $sorted = $this->commands;
        ksort($sorted);

        return $sorted;
    }

    /**
     * Parse CLI arguments and execute the requested command.
     *
     * @param list<string> $argv
     */
    public function run(array $argv, ?ConsoleOutput $output = null): int
    {
        $output ??= new ConsoleOutput(false);
        $this->bootCommands();

        $commandName = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if ($commandName === '-h' || $commandName === '--help') {
            $commandName = 'list';
        } elseif ($commandName === '-v' || $commandName === '--version') {
            $output->writeln(sprintf('Prime CMS %s', Application::VERSION));

            return CommandInterface::SUCCESS;
        }

        if (!isset($this->commands[$commandName])) {
            $output->error(sprintf('Commande inconnue : \"%s\".', $commandName));
            $output->error('Exécutez \"php bin/prime list\" pour voir les commandes disponibles.');

            return CommandInterface::FAILURE;
        }

        return $this->commands[$commandName]->execute($args, $output);
    }

    private function bootCommands(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        /** @var Router $router */
        $router = $this->app->get(Router::class);

        $this->register(new ListCommand($this));
        $this->register(new AboutCommand($this->app));
        $this->register(new AssetsBuildCommand($this->app));
        $this->register(new RoutesCommand($router));
        $this->register(new LangCheckCommand($this->app));

        if ($this->app->container()->has(CacheManager::class)) {
            /** @var CacheManager $cacheManager */
            $cacheManager = $this->app->get(CacheManager::class);

            $this->register(new CacheClearCommand($cacheManager));
        }

        if ($this->app->container()->has(Connection::class)) {
            /** @var Connection $connection */
            $connection = $this->app->get(Connection::class);
            /** @var Migrator $migrator */
            $migrator = $this->app->get(Migrator::class);

            $this->register(new DbCheckCommand($connection));
            $this->register(new MigrateCommand($migrator));
            $this->register(new MigrateRollbackCommand($migrator));
            $this->register(new MigrateStatusCommand($migrator));
            $this->register(new MigrateResetCommand($migrator));
            $this->register(new MigrateFreshCommand($this->app, $connection, $migrator));
            $this->register(new MakeMigrationCommand($this->app));
        }

        if ($this->app->container()->has(Schedule::class) && $this->app->container()->has(SchedulerRunner::class)) {
            /** @var Schedule $schedule */
            $schedule = $this->app->get(Schedule::class);
            /** @var SchedulerRunner $runner */
            $runner = $this->app->get(SchedulerRunner::class);

            $this->register(new ScheduleRunCommand($schedule, $runner));
        }

        if ($this->app->container()->has(JobQueue::class) && $this->app->container()->has(JobRepository::class)) {
            /** @var JobQueue $queue */
            $queue = $this->app->get(JobQueue::class);
            /** @var JobRepository $jobs */
            $jobs = $this->app->get(JobRepository::class);

            $this->register(new QueueWorkCommand($queue, $jobs));
            $this->register(new QueueStatusCommand($jobs));
            $this->register(new QueueRetryCommand($jobs));
        }

        // `bin/prime list` must answer on a site whose database is unreachable, so nothing is resolved
        // here: the commands take the container and fetch the mailer when they actually run.
        if ($this->app->container()->has(Mailer::class)) {
            $c = $this->app->container();

            $this->register(new MailTestCommand($c));
            $this->register(new MailLogsCommand($c));
            $this->register(new MailPruneCommand($c));
        }

        if ($this->app->container()->has(Settings::class)) {
            /** @var Settings $settings */
            $settings = $this->app->get(Settings::class);

            $this->register(new SettingsGetCommand($settings));
            $this->register(new SettingsSetCommand($settings));

            if ($this->app->container()->has(SettingsRegistry::class)) {
                /** @var SettingsRegistry $registry */
                $registry = $this->app->get(SettingsRegistry::class);

                $this->register(new SettingsListCommand($registry, $settings));
            }
        }

        if ($this->app->container()->has(Installer::class)) {
            /** @var Installer $installer */
            $installer = $this->app->get(Installer::class);

            $this->register(new InstallCommand($this->app->config(), $installer));
        }

        if ($this->app->container()->has(UserRepository::class) && $this->app->container()->has(RoleRepository::class)) {
            /** @var UserRepository $userRepository */
            $userRepository = $this->app->get(UserRepository::class);
            /** @var RoleRepository $roleRepository */
            $roleRepository = $this->app->get(RoleRepository::class);
            /** @var UserGuard $userGuard */
            $userGuard = $this->app->get(UserGuard::class);
            /** @var PasswordPolicy $passwordPolicy */
            $passwordPolicy = $this->app->get(PasswordPolicy::class);

            $this->register(new UserCreateCommand($userRepository, $roleRepository, $passwordPolicy));
            $this->register(new UserListCommand($userRepository));
            $this->register(new UserPasswordCommand($userRepository, $passwordPolicy));
            $this->register(new UserRoleCommand($userRepository, $roleRepository, $userGuard));
            $this->register(new UserDisableCommand($userRepository, $userGuard));
            $this->register(new UserEnableCommand($userRepository));
            $this->register(new UserTwoFactorResetCommand($this->app));
            $this->register(new RoleListCommand($roleRepository));
        }

        if ($this->app->container()->has(PluginManager::class)) {
            /** @var PluginManager $pluginManager */
            $pluginManager = $this->app->get(PluginManager::class);
            /** @var PluginInstaller $pluginInstaller */
            $pluginInstaller = $this->app->get(PluginInstaller::class);

            $this->register(new PluginListCommand($pluginManager));
            $this->register(new PluginActivateCommand($pluginManager));
            $this->register(new PluginDeactivateCommand($pluginManager));
            $this->register(new PluginUninstallCommand($pluginManager));
            $this->register(new PluginInstallCommand($pluginInstaller));
            $this->register(new PluginPublishAssetsCommand($pluginManager));
        }

        if ($this->app->container()->has(EntryService::class) && $this->app->container()->has(ContentTypeRegistry::class)) {
            /** @var EntryQuery $entryQuery */
            $entryQuery = $this->app->get(EntryQuery::class);
            /** @var EntryService $entryService */
            $entryService = $this->app->get(EntryService::class);
            /** @var ContentTypeRegistry $contentTypes */
            $contentTypes = $this->app->get(ContentTypeRegistry::class);
            /** @var TaxonomyRegistry $taxonomies */
            $taxonomies = $this->app->get(TaxonomyRegistry::class);
            /** @var UserRepository $contentUsers */
            $contentUsers = $this->app->get(UserRepository::class);

            $this->register(new ContentListCommand($entryQuery));
            $this->register(new ContentCreateCommand($entryService, $contentUsers));
            $this->register(new ContentPublishScheduledCommand($entryService));
            $this->register(new ContentPurgeTrashCommand($this->app, $entryService));
            $this->register(new ContentRerenderCommand($entryService));
            $this->register(new ContentTypesCommand($contentTypes, $taxonomies));
        }

        if ($this->app->container()->has(MediaRepository::class) && $this->app->container()->has(UploadService::class)) {
            /** @var MediaRepository $mediaRepo */
            $mediaRepo = $this->app->get(MediaRepository::class);
            /** @var UploadService $uploadService */
            $uploadService = $this->app->get(UploadService::class);
            /** @var MediaConfig $mediaConfig */
            $mediaConfig = $this->app->get(MediaConfig::class);
            /** @var UploadsGuard $uploadsGuard */
            $uploadsGuard = $this->app->get(UploadsGuard::class);

            $this->register(new MediaRegenerateCommand($mediaRepo, $uploadService, $mediaConfig));
            $this->register(new MediaLinkCommand($uploadsGuard));
            $this->register(new MediaCleanupCommand($mediaRepo, $mediaConfig));
        }

        if (
            $this->app->container()->has(ApiTokenRepository::class)
            && $this->app->container()->has(ApiTokenService::class)
            && $this->app->container()->has(UserRepository::class)
        ) {
            /** @var ApiTokenRepository $tokens */
            $tokens = $this->app->get(ApiTokenRepository::class);
            /** @var ApiTokenService $tokenService */
            $tokenService = $this->app->get(ApiTokenService::class);
            /** @var UserRepository $users */
            $users = $this->app->get(UserRepository::class);

            $this->register(new ApiTokenCreateCommand($tokenService, $users));
            $this->register(new ApiTokenListCommand($tokens, $users));
            $this->register(new ApiTokenRevokeCommand($tokens));
        }

        if ($this->app->container()->has(ThemeManager::class)) {
            /** @var ThemeManager $themeManager */
            $themeManager = $this->app->get(ThemeManager::class);

            $this->register(new ThemeListCommand($themeManager));
            $this->register(new ThemeActivateCommand($themeManager));
            $this->register(new ThemePublishCommand($themeManager));
        }

        $this->register(new MakePluginCommand($this->app));

        if ($this->app->container()->has(Hooks::class)) {
            /** @var Hooks $hooks */
            $hooks = $this->app->get(Hooks::class);
            $hooks->emit('console.commands', $this);
        }
    }
}
