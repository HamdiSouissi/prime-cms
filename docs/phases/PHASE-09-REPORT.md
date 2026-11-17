# Phase 9 — Réglages, langues, cache, e-mails et tâches (v0.9.0)

- **Branche** : `claude/phase-09` (consolidation des livraisons `arena/ac78fd7d-prime-cms` et `arena/0af7686b-prime-cms`, puis finalisation)
- **Version noyau** : `0.9.0` (`Prime\Kernel\Application::VERSION`)
- **Migrations du noyau** : 25 (`000024_create_mail_log_table`, `000025_create_jobs_table`)

---

## 1. Livrables

| Lot | Périmètre | État |
|-----|-----------|------|
| **L1** | API de réglages : `SettingsRegistry` (sections, champs, sources d'options paresseuses, conditions), `SettingsFormProcessor` (validation groupée, chiffrement des secrets, hooks `settings.*`), `SettingsText` (libellés traduits), écrans générés par `SettingsPageController`. `hello-prime` déclare sa section « Message d'accueil » via `settings.register`, qui remplace son formulaire fait à la main. | Terminé |
| **L2** | Pages **Général** (identité, langues, fuseau, formats date/heure avec préréglages et aperçu en direct, premier jour, écriture, maintenance, visibilité) et **Sécurité** (politique de mots de passe, sessions, limiteur, 2FA obligatoire par rôle, en-têtes de sécurité actifs en lecture seule). 2FA de bout en bout : redirection forcée vers l'activation, **QR code SVG inline** (`Support\Barcode`, PHP pur), clé copiable, lien `otpauth://`. | Terminé |
| **L3** | i18n : `Translator` (catalogues `core/admin/site/mail/validation`, espaces `plugin::`/`theme::`, pluriels `{n}`/`[a,b]` côté PHP **et** JS), `LocaleFormatter` sans `intl`, `t()`/`$this->t()`/`tr()`/`trChoice()`, langue par utilisateur, `LabelTranslator` pour les libellés stockés comme données (types de contenus, taxonomies, menus, rôles, capacités, blocs, manifestes, réglages). Admin, installeur, erreurs, e-mails, Aurora et `hello-prime` entièrement traduits FR/EN. `lang:check --strict` vert en CI. | Terminé |
| **L4** | Cache de données (`file`/`apcu`/`array`, espaces de noms) et cache de pages (`X-Prime-Cache`, `ETag`/304, purge ciblée), écran Performance, bouton « Vider le cache » (barre du haut et palette), `cache:clear`. | Terminé |
| **L5** | E-mails : SMTP maison, `native`, `log`, `null`, file (`queue`), `sendNow()`, modèles HTML (réinitialisation, invitation, mot de passe modifié, **nouveau contenu à relire**, **mise à jour de sécurité**), journal `pc_mail_log` sans corps et avec données sensibles masquées, écran E-mails (test, journal des 50 derniers envois filtrable, « Renvoyer »). | Terminé |
| **L6** | File `pc_jobs` (réservation atomique, temporisation, libération des travaux bloqués), planificateur (`CronExpression`, verrous, repli au passage des visiteurs, battement de cœur), tâches du noyau, écran **Outils › Tâches planifiées** (fréquence en clair, dernière/prochaine exécution, « Exécuter », bandeau cron arrêté) et **File d'attente** (Relancer, Tout relancer, Supprimer avec confirmation). | Terminé |
| **L7** | **Outils › État du système** : PHP et extensions, base de données, dossiers, disque, debug, `APP_KEY`, HTTPS, cache de pages, planificateur, file, e-mails, plugins en erreur ; filtre `system.checks` ; rapport copiable/téléchargeable sans secret ; alerte au tableau de bord en cas d'erreur bloquante. | Terminé |
| **L8** | Groupe **Outils** dans la barre latérale (Tâches planifiées, File d'attente, État du système), sous-menu Réglages complet, palette de commandes (pages + action « Vider le cache »). | Terminé |
| **L9** | Tests : 746 PHPUnit (dont tests `database` MySQL 8.0 / MariaDB 10.6 en CI), 52 tests JS. Nouveaux : `AdminSettingsToolsTest` (10 comportements), 2FA forcée de bout en bout, pluriels explicites, `CronDescriber`, `runOne`/battement de cœur, `LanguageChecker` (libellés-données), aperçu de date, champs conditionnels, avertissement de modifications non enregistrées. | Terminé |
| **L10** | `docs/settings.md`, `docs/i18n.md`, `docs/cache.md`, `docs/mail.md`, `docs/scheduler.md`, mises à jour `hooks.md`, `plugins.md`, `README.md`, présent rapport, captures `screens/phase-09/`. | Terminé |

---

## 2. Sécurité

- Toutes les routes Réglages et Outils exigent `settings.manage` côté serveur ; toutes les actions sont en `POST` + CSRF.
- Secrets (`mail.password`) chiffrés AES-256-GCM, jamais réaffichés ; champ vide = valeur conservée (testé, y compris l'absence du secret en clair en base).
- Journal des e-mails : aucun corps stocké ; les données de gabarit contenant `token`, `url`, `link`, `code`, `password`… sont masquées, et un message masqué ne peut pas être renvoyé (un lien de réinitialisation n'est jamais rejoué).
- Rapport système : versions et verdicts seulement ; identifiants dans les URL et `password=…` masqués même s'ils viennent d'un plugin.
- Mode maintenance : 503 + `Retry-After` pour tous sauf `settings.manage` ; site privé : redirection vers la connexion ; le cache de pages est purgé à chaque enregistrement de réglage.

---

## 3. Vérifications dans le navigateur (Chromium, 1440 px et 375 px)

| Écran / action | Constat |
|----------------|---------|
| Réglages › Général | 5 cartes (Général, Langues, Écriture, Avancé, section « Hello Prime » du plugin). Saisie `l j F Y` → aperçu « mardi 17 novembre 2026 » en direct. |
| Réglages › Sécurité | Saisie d'une longueur `3` → résumé d'erreurs focalisé, message sous le champ, valeur conservée, rien d'enregistré ; `12` → « Réglages enregistrés ». Carte des en-têtes de sécurité affichée. |
| Réglages › E-mails | Méthode « Journal de bord » : bandeau d'information, champs SMTP masqués ; passage à « Serveur SMTP » : champs affichés. « Envoyer le test » → toast et ligne dans le journal ; « Renvoyer » → seconde ligne. Filtres Tous/Envoyé/Échec/En file. |
| Profil › 2FA | QR code affiché avec la clé et le lien ; décodé par `pyzbar` depuis la capture (URI `otpauth://` correcte). Rôle soumis à la 2FA : toute page admin redirige vers l'activation, le profil reste accessible. |
| Outils › Tâches planifiées | 4 tâches, fréquences en clair (« Toutes les minutes », « Chaque jour à 03:30 »…), « Exécuter » → toast « exécutée (3 ms) ». |
| Outils › File d'attente / État du système | Compteurs, listes, actions ; rapport copiable ; HTTPS en erreur sur l'environnement local (attendu). |
| Palette (Ctrl+K) | « cache » → action « Vider le cache ». |
| Mobile 375 px | Aucun débordement horizontal de page (`scrollWidth` = 375) ; tableaux défilants dans leur carte. |
| Anglais | Profil en `en` : écrans E-mails et État du système entièrement en anglais (seules les données utilisateur restent en français) ; retour en `fr` vérifié. |
| Console | 0 erreur JavaScript sur l'ensemble du parcours. |

Captures : `docs/phases/screens/phase-09/01` à `14` (Général, Sécurité, E-mails, Performance, Tâches, File, État du système, tableau de bord, mobile, anglais, QR 2FA, e-mail HTML).

---

## 4. Résultats automatisés

- PHPUnit : **746 tests**, 10 116 assertions, 0 échec (local, avec MariaDB) ; CI : PHP 8.2/8.3, `database` sur MySQL 8.0 et MariaDB 10.6.
- Tests JS : **52**, 0 échec.
- PHPStan niveau 8 : 0 erreur. PHP-CS-Fixer : 0 fichier. `lang:check --strict` : 0 erreur, 0 avertissement.

---

## 5. Points ouverts (phases suivantes)

- Le fuseau horaire choisi alimente l'affichage ; la liste des fuseaux est une liste simple (pas encore groupée par continent avec recherche).
- Le champ `media` des réglages (logo, icône, image de partage) saisit un identifiant ; le sélecteur visuel de médias sera branché avec l'API REST (phase 10).
- L'italien (prévu au `CONTEXT.md`) n'est pas livré : le prompt de la phase limitait la livraison au français et à l'anglais ; ajouter une langue se fait par simple dépôt de catalogues (`docs/i18n.md`).
- Le contrôleur `DashboardController::module()` et les données de démonstration associées ne sont plus routés ; ils seront retirés avec la refonte du tableau de bord.
