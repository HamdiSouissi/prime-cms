# Mettre à jour Prime CMS

Prime CMS n'a pas de serveur de mises à jour : vous déployez le nouveau code vous-même (Git, archive),
puis une seule commande, ou un bouton, met la base et les fichiers publics à niveau.

## En bref

```bash
# 1. Sauvegarder la base (ou laisser la commande le faire avec --backup)
mysqldump --single-transaction prime_cms > sauvegarde.sql

# 2. Déployer le nouveau code
git pull                      # ou décompresser la nouvelle archive par-dessus l'ancienne

# 3. Appliquer la mise à jour
php bin/prime update --check        # voir ce qui va être fait, sans rien changer
php bin/prime update --backup-done  # vous avez sauvegardé vous-même
php bin/prime update --backup       # ou : sauvegarde automatique avec mysqldump
```

Depuis l'administration : **Outils › Mises à jour**, qui affiche la version installée, celle du code
déployé, les migrations en attente (noyau et plugins) et la dernière mise à jour. Le bouton
« Mettre à jour » lance la même procédure.

## Ce que fait la mise à jour

1. **Sauvegarde.** Refus si elle n'est ni confirmée (`--backup-done`, case « Je confirme… ») ni
   demandée (`--backup`). La sauvegarde automatique utilise `mysqldump` (ou `mariadb-dump`) et
   l'écrit dans `storage/backups/prime-AAAAMMJJ-HHMMSS.sql`, hors du dossier public ; le mot de
   passe passe par l'environnement, jamais par la ligne de commande.
2. **Maintenance.** Le site public répond 503 pendant l'opération (l'administration reste ouverte).
3. **Migrations du noyau**, puis **migrations des plugins actifs**.
4. **Fichiers publics** des plugins et des thèmes republiés.
5. **Caches** vidés (données et pages).
6. **Version enregistrée** (`prime_version`) et résumé dans `update.last_run`.
7. **Fin de la maintenance**, ou maintien si elle était déjà active avant.

Chaque étape est écrite dans le journal (`storage/logs/prime-AAAA-MM-JJ.log`). La première erreur
arrête la mise à jour, le message s'affiche et la maintenance est levée.

## En cas de problème

- **« Sauvegardez la base avant la mise à jour »** : relancez avec `--backup-done` ou `--backup`.
- **« mysqldump est introuvable »** : installez le client MySQL/MariaDB ou sauvegardez vous-même.
- **Une migration échoue** : rien n'est enregistré comme fait pour cette migration ; corrigez la
  cause indiquée (droits SQL, espace disque) et relancez. Pour revenir en arrière, restaurez la
  sauvegarde puis redéployez l'ancien code.
- **Site resté en maintenance** après un arrêt brutal du processus : décochez « Maintenance » dans
  **Réglages › Avancé**, ou `php bin/prime settings:set advanced.maintenance false`.

## Notes de version

| Depuis | Points d'attention |
|--------|--------------------|
| 0.9 | L'API REST (phase 10) ajoute la table `pc_api_tokens` ; la phase 12 ajoute des index (migration `000027`). Aucune action manuelle. |
| 0.10 | `prime-starter` est fourni mais n'est activé automatiquement que sur une installation neuve : activez-le dans **Plugins** si vous voulez le carnet de notes. |
| 0.11 | Rien à faire. |
