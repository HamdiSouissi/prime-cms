# Hello Prime

Plugin officiel de référence pour **Prime CMS** démontrant l'ensemble de l'API plugins (`Prime\Plugins\PluginContext`) sans aucun contournement du noyau.

## Fonctionnalités illustrées

- Manifeste `plugin.json` complet (SemVer, contraintes `requires`, icône SVG, accent `violet`, `settings_url`).
- Capacité personnalisée `hello_prime.manage` enregistrée dans le groupe **Hello Prime**.
- Entrée de menu d'administration (accent violet) et page conforme au design system (`/admin/hello-prime`).
- Réglages préfixés (`plugin.hello-prime.greeting`) via `PluginContext::settings()`.
- Migration SQL dédiée (`pc_hello_prime_greetings`) exécutée à l'activation et annulée à la désinstallation.
- Route publique `GET /hello-prime` rendue via l'espace de vues `hello-prime::public`.
- Feuille de style publiée dans `public/assets/plugins/hello-prime/` et chargée via le filtre `admin.assets`.
- Notification persistante d'accueil via `PluginContext::notices()`.
- Raccourci ajouté sur le tableau de bord via le filtre `admin.dashboard.shortcuts`.
