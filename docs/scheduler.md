# Planificateur et file de tâches

## 1. Mise en place

Une seule ligne de crontab suffit :

```cron
* * * * * cd /chemin/vers/prime-cms && php bin/prime schedule:run >> /dev/null 2>&1
```

Sans accès à cron, laissez **« Exécuter au passage des visiteurs »** (`scheduler.fallback`)
activé : `SchedulerFallback` lance le planificateur au plus une fois par `scheduler.interval`
secondes, après l'envoi de la réponse. Avec Docker, le service `scheduler` s'en charge.

**Outils › Tâches planifiées** (`/admin/tools/scheduler`) liste les tâches, leur expression cron,
dernière et prochaine exécution, la dernière erreur, propose « Exécuter » et affiche un
avertissement si aucun passage n'a eu lieu depuis 10 minutes (fichier `heartbeat`).

## 2. Tâches du noyau

| Nom | Fréquence | Rôle |
|-----|-----------|------|
| `content.publish_scheduled` | chaque minute | Publie les contenus programmés. |
| `content.purge_trash` | 03:30 | Vide la corbeille au-delà de `writing.trash_days`. |
| `jobs.prune` | :15 chaque heure | Libère les réservations bloquées, purge les échecs anciens. |
| `mail.prune_log` | 03:45 | Applique la rétention du journal des e-mails. |

### Ajouter une tâche (plugin)

```php
$hooks->filter('scheduler.register', function (Schedule $schedule): Schedule {
    $schedule->call('shop.sync_stock', fn () => $sync->run(), '*/15 * * * *', 'plugin::shop.tasks.sync');
    $schedule->command('shop.report', 'shop:report --daily', '0 6 * * *');
    return $schedule;
});
```

Les tâches ne se chevauchent pas (verrou par tâche), une tâche en erreur n'est pas relancée en
boucle (l'échéance est consignée, l'erreur affichée).

## 3. File de tâches

`JobQueue::dispatch($handler, $payload, $queue, $delay)` enregistre une tâche dans `pc_jobs` ;
`queue:work` la traite avec nouvelles tentatives (`scheduler.queue_tries`) et temporisation
exponentielle. **Outils › File d'attente** affiche les compteurs, les tâches en attente et en
échec, avec « Relancer », « Tout relancer » et « Supprimer ».

```php
$hooks->filter('jobs.register_handlers', function (JobHandlerRegistry $registry): JobHandlerRegistry {
    $registry->register('shop.invoice', InvoiceJob::class);
    return $registry;
});
```

```bash
php bin/prime schedule:run [--force] [--list] [--json]
php bin/prime queue:work [--queue=default] [--max=50] [--once] [--forever]
php bin/prime queue:status [--failed] [--json]
php bin/prime queue:retry [--all] [--id=N] [--prune=60]
```

## 4. État du système

**Outils › État du système** (`/admin/tools/system`) vérifie PHP et ses extensions, la base de
données, les dossiers inscriptibles, l'espace disque, le mode débogage, `APP_KEY`, HTTPS, le
cache, le planificateur, la file et l'envoi d'e-mails. Le **rapport** copiable ou téléchargeable
ne contient aucun secret (les identifiants dans les URL et les `password=…` sont masqués).

```php
$hooks->filter('system.checks', function (array $checks): array {
    $checks[] = ['id' => 'shop.gateway', 'group' => 'plugins', 'label' => 'Passerelle de paiement',
                 'status' => 'ok', 'value' => 'Stripe (live)', 'help' => null];
    return $checks;
});
```

Statuts : `ok`, `warning`, `error`, `info`.

## 5. Hooks

`scheduler.register`, `jobs.register_handlers`, `system.checks` (filtres) ; `scheduler.ran`,
`scheduler.task.ran`, `job.pushed`, `job.started`, `job.completed`, `job.released`,
`job.failed` (événements).
