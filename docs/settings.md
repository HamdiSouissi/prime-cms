# Réglages — registre, écrans et API

Depuis la phase 9, tous les réglages du site sont **déclarés** dans un registre unique
(`Prime\Settings\SettingsRegistry`). Les écrans d'administration, la CLI et l'API de lecture
s'appuient sur cette déclaration : un réglage déclaré une fois apparaît partout, validé et typé.

## 1. Déclaration

Le noyau déclare ses sections et ses champs dans `config/settings.php` :

```php
'sections' => [
    'mail' => ['label' => 'E-mails', 'description' => '…', 'icon' => 'mail', 'priority' => 90, 'page' => 'general'],
],
'fields' => [
    ['name' => 'mail.driver', 'label' => 'Méthode d’envoi', 'type' => 'select', 'section' => 'mail',
     'default' => 'log', 'options' => ['log' => '…', 'smtp' => '…']],
    ['name' => 'mail.host', 'label' => 'Hôte SMTP', 'type' => 'text', 'section' => 'mail',
     'rules' => 'max:191', 'conditions' => ['field' => 'mail.driver', 'value' => ['smtp', 'queue']]],
],
```

| Clé | Rôle |
|-----|------|
| `name` | Nom unique `section.cle` (stocké dans `pc_settings`). |
| `type` | `text`, `textarea`, `code`, `number`, `email`, `url`, `checkbox`, `toggle`, `radio`, `select`, `multiselect`, `color`, `date`, `datetime`, `time`, `media`, `hidden`, `secret`. |
| `rules` | Règles du `Validator` (`required|min:8|max:64`, `email`, `in:…`). |
| `options` | Tableau statique **ou** nom d'une source paresseuse (`pages`, `categories`, `roles`, `timezones`, `locales`). |
| `conditions` | Affiche le champ seulement si un autre champ a la valeur attendue (gérée côté client par `settings-admin.js`). |
| `public` | Exposé aux thèmes via `SettingsRegistry::publicValues()`. |
| `secret` | Valeur chiffrée AES-256-GCM (`Settings::setSecret()`), jamais renvoyée au navigateur. |

### Depuis un plugin

```php
$hooks->filter('settings.register', function (SettingsRegistry $registry): SettingsRegistry {
    $registry->section('shop', ['label' => 'plugin::shop.settings.title', 'page' => 'general']);
    $registry->add(['name' => 'shop.currency', 'label' => 'plugin::shop.settings.currency',
                    'type' => 'select', 'section' => 'shop', 'options' => ['EUR' => 'Euro']], 'shop');
    return $registry;
});
```

Les libellés peuvent être des **clés de traduction** ou des littéraux : ils passent par
`LabelTranslator`. Une section dont `page` vaut `general`, `security` ou `emails` s'affiche
automatiquement sur l'écran correspondant.

## 2. Écrans

| Écran | URL | Sections |
|-------|-----|----------|
| Général | `/admin/settings/general` | `general`, `languages`, `writing`, `advanced` (+ sections de plugins) |
| Lecture / Permaliens / Référencement | `/admin/settings/{reading,permalinks,seo}` | écrans dédiés (phase 8) |
| Sécurité | `/admin/settings/security` | `security` (mots de passe, sessions, 2FA obligatoire par rôle) |
| E-mails | `/admin/settings/emails` | `mail` + e-mail de test + journal |
| Performance | `/admin/settings/performance` | cache de données et de pages |

Comportement commun (`SettingsPageController`) :

- **Validation groupée** : si un champ est invalide, *rien* n'est enregistré ; les valeurs saisies
  sont réaffichées, un résumé d'erreurs reçoit le focus.
- Seules les clés réellement postées sont traitées (les interrupteurs et listes envoient un champ
  caché de repli, une absence signifie « pas sur ce formulaire »).
- Les **secrets** ne sont jamais réaffichés : le champ indique « Défini », le laisser vide conserve la valeur.
- Tout enregistrement effectif vide le **cache de pages** (titre, maintenance, visibilité…).
- Capacité requise : `settings.manage`.

Effets côté site public : `advanced.maintenance` renvoie une page **503** (`Retry-After: 600`) à
tous sauf aux comptes `settings.manage` ; `general.privacy` redirige les visiteurs non connectés
vers `/admin/login`.

## 3. Traduction des libellés

Les libellés du noyau restent en français dans `config/settings.php` (lisibles, affichés par la
CLI). Leurs traductions sont rangées sous `core.settings.sections.<id>.{label,description}` et
`core.settings.fields.<name>.{label,description,options.<valeur>}`. `Prime\Settings\SettingsText`
cherche la clé et retombe sur le littéral, ce qui permet à un plugin de ne rien déclarer deux fois.

## 4. CLI

```bash
php bin/prime settings:list [section] [--json]
php bin/prime settings:get mail.driver
php bin/prime settings:set mail.driver '"smtp"'
```

## 5. Hooks

| Hook | Type | Usage |
|------|------|-------|
| `settings.register` | filtre | Déclarer sections et champs (reçoit le registre). |
| `settings.validate` | filtre | Ajuster une valeur après validation. |
| `settings.before_save` | filtre | Retourner `false` pour refuser l'écriture d'un réglage. |
| `settings.saved` | événement | `(section, noms enregistrés, erreurs)`. |
| `settings.updated` | événement | Émis par `Settings::set()`. |
