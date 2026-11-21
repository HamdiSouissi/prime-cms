# Prime Starter

Carnet de notes en Markdown, et modèle copié par `php bin/prime make:plugin <slug>`.

Chaque point d'extension de Prime CMS est utilisé une fois dans `src/Plugin.php` (sections
numérotées) : capacité, réglages, écrans d'admin, page publique dans le thème, API REST, bloc Prime
Editor, bloc de zone, éditeur alternatif, tâche planifiée, travail en file, e-mail et diagnostic.

```bash
php bin/prime plugin:activate prime-starter
vendor/bin/phpunit content/plugins/prime-starter/tests
```

Documentation : `docs/plugin-starter.md`.
