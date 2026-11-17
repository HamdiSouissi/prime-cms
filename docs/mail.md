# E-mails

## 1. Configuration

Écran **Réglages › E-mails** (`/admin/settings/emails`) ou variables `MAIL_*` (`config/mail.php`).
Les réglages de l'écran l'emportent sur le `.env`.

| Méthode (`mail.driver`) | Effet |
|-------------------------|-------|
| `log` | Rien n'est envoyé : les messages sont seulement journalisés (défaut, développement). |
| `native` | Fonction `mail()` de PHP. |
| `smtp` | `SmtpTransport` natif (STARTTLS ou SSL implicite, AUTH LOGIN/PLAIN, vérification du certificat). |
| `queue` | SMTP via la file de tâches (`SendMailJob`, nouvelles tentatives avec temporisation). |

Le mot de passe SMTP est un réglage `secret` chiffré, jamais réaffiché.

## 2. Envoyer

```php
$mailer = $app->get(Prime\Mail\Mailer::class);
$mailer->sendTemplate('auth/reset', $user->email, t('core.mail.reset.subject'), ['url' => $link]);
$mailer->dispatch(new Message(to: 'a@b.test', subject: '…', html: '…', text: '…'));
```

`dispatch()` passe par la file quand la méthode est `queue`, `deliver()` envoie immédiatement.

## 3. Journal

Chaque envoi est consigné dans `pc_mail_log` (destinataire, objet, statut `sent|failed|queued`,
transport, gabarit, tentatives, erreur). **Aucun corps de message n'est stocké**, et les données
de gabarit dont la clé évoque un secret (`token`, `url`, `link`, `code`, `password`…) sont
remplacées par `[masqué]` avant écriture.

L'écran E-mails affiche les 50 derniers envois, filtrables par statut. « Renvoyer » n'est proposé
que si le message peut être reconstruit sans secret (e-mail de test, gabarit sans donnée masquée).
La rétention (`mail.log_days`) est appliquée chaque nuit par la tâche `mail.prune_log`.

## 4. CLI

```bash
php bin/prime mail:test --to=vous@exemple.fr
php bin/prime mail:logs --status=failed --limit=25
php bin/prime mail:prune --days=30 [--dry-run]
```

## 5. Hooks

`mail.message` (filtre, réécrire un message), `mail.sent`, `mail.failed`, `mail.log.recorded`,
`mail.log.purged` (événements).
