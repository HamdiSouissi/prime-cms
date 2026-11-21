# Guide de l'administrateur

Ce guide s'adresse à la personne qui gère le site au quotidien. Il suit le menu de
l'administration, de haut en bas. L'installation est décrite dans [`install.md`](install.md),
les mises à jour dans [`upgrade.md`](upgrade.md).

## Prise en main

1. Ouvrez `/admin` et connectez-vous avec l'adresse et le mot de passe choisis à l'installation.
2. Le **tableau de bord** résume l'activité : contenus récents, brouillons, e-mails en échec,
   état du système. Chaque tuile mène à l'écran concerné.
3. La **barre de recherche** en haut (`Ctrl` + `K`, ou `/`) ouvre n'importe quel écran ou action :
   tapez « article », « médias », « jeton »…
4. Le bouton **Créer** propose les créations les plus courantes (article, page, média).
5. Le **menu latéral** se replie (bouton « Réduire ») ; sur mobile, il s'ouvre par le bouton menu.

Pour découvrir le thème avec du contenu, cochez « Installer le contenu de démonstration » à la
dernière étape de l'installation, ou lancez `php bin/prime demo:install`. Tout ce contenu se retire
avec `php bin/prime demo:remove`.

## Contenus

### Pages et articles

- **Liste** : recherche, filtres par statut, auteur, catégorie et mois ; actions groupées
  (publier, mettre à la corbeille).
- **Édition** : le titre en haut, le corps dans **Prime Editor**. Tapez `/` dans un paragraphe pour
  insérer un bloc (titre, image, liste, citation, tableau, vidéo…). Les blocs se déplacent avec
  leur barre d'outils (monter, descendre) ou par glisser-déposer.
- **Panneau latéral** : statut, date de publication (une date future planifie l'article), adresse
  (slug), auteur, extrait, image à la une, catégories et étiquettes, SEO (titre, description,
  image de partage, « ne pas indexer »).
- **Enregistrer le brouillon** garde le contenu privé ; **Publier** le met en ligne. Les
  **révisions** permettent de revenir à une version précédente.
- Si quelqu'un d'autre modifie le même contenu, un **verrou** vous prévient.
- La **corbeille** garde les contenus supprimés 30 jours (réglable), puis les supprime.

Avec le plugin **Prime Starter**, le réglage « Écrire les articles en Markdown » remplace Prime
Editor par un éditeur Markdown sur les articles.

### Catégories et étiquettes

Sous **Articles › Catégories** et **Articles › Étiquettes** : nom, adresse, description, parent
(catégories). Supprimer un terme le retire des contenus sans supprimer ceux-ci.

## Médias

- **Envoyer** : glissez des fichiers sur la médiathèque ou utilisez le bouton « Téléverser ».
  Images, documents PDF et bureautiques, audio, vidéo et archives sont acceptés ; le type réel du
  fichier est vérifié.
- **Détails** (clic sur un média) : titre, **texte alternatif** (obligatoire pour l'accessibilité
  des images informatives), légende, dossier, point focal pour le recadrage.
- **Dossiers** pour ranger, filtre « Inutilisés » pour retrouver les fichiers qui ne servent nulle
  part avant de les supprimer.
- Les variantes (miniatures, WebP) sont produites automatiquement.

## Apparence

- **Thèmes** : activer un thème ; Aurora est fourni.
- **Personnaliser** : couleurs, typographie, logo, en-tête et pied de page, avec aperçu en direct.
- **Menus** : construisez les menus (pages, articles, catégories, liens) et placez-les dans les
  emplacements du thème (navigation principale, pied de page).
- **Zones** : remplissez la barre latérale et les colonnes du pied de page avec des blocs, dont des
  blocs dynamiques (derniers articles, liste de catégories, notes du carnet…).

## Réglages

- **Général** : nom, sous-titre, description, e-mail d'administration, logo et icône, fuseau
  horaire, premier jour de la semaine, langues ; sections **E-mails** (expéditeur, SMTP ou envoi
  natif, test), **Tâches planifiées** et **Avancé** (maintenance du site public, site privé).
- **Lecture** : page d'accueil (derniers articles ou page fixe), nombre d'articles par page.
- **Permaliens** : forme des adresses des articles, pages et archives.
- **SEO** : titres, partage sur les réseaux, sitemap, `robots.txt`.
- **Sécurité** : 2FA obligatoire par rôle, sessions, limites de connexion, API REST (activation,
  lecture publique, origines CORS, limites de débit, durée maximale des jetons).
- Les plugins ajoutent leurs propres sections (par exemple « Carnet » pour Prime Starter).

## Outils

- **Tâches planifiées** : tâches régulières (publication planifiée, nettoyage, résumés) ; dernier et
  prochain passage, exécution manuelle. Le serveur doit lancer `php bin/prime schedule:run` chaque
  minute (cron) ; avec Docker, c'est le service `scheduler`.
- **File d'attente** : travaux en arrière-plan (e-mails…), relance des travaux en échec.
- **État du système** : versions, extensions PHP, droits d'écriture, base, cache, e-mails, plugins.
  Un point rouge indique une action à mener.
- **API REST** : état de l'API, jetons actifs de tous les comptes (révocation), lien vers le
  document OpenAPI. Le guide développeur est [`api.md`](api.md).
- **Mises à jour** : version installée et version déployée, migrations en attente, bouton
  « Mettre à jour » ([`upgrade.md`](upgrade.md)).

## Utilisateurs et rôles

- **Utilisateurs** : créer un compte ou inviter par e-mail (lien valable 48 h), désactiver,
  envoyer un lien de réinitialisation du mot de passe, déconnecter tous ses appareils,
  réinitialiser la 2FA d'un compte qui a perdu son appareil, révoquer ses jetons d'API.
- **Rôles** : administrateur, éditeur, auteur, contributeur, et vos propres rôles avec une
  sélection de permissions (une matrice par module). Le rôle administrateur ne peut pas être
  retiré au dernier administrateur.
- **Profil** (menu en haut à droite) : informations, langue de l'interface, mot de passe,
  **double authentification** (QR code à scanner avec Aegis, Google Authenticator, 1Password…,
  puis codes de récupération à conserver), déconnexion de tous les appareils, **jetons d'API**.

## Plugins

- **Plugins** : activer, désactiver, désinstaller (supprime les données du plugin), installer une
  archive ZIP.
- **Prime Starter** (actif après l'installation) ajoute **Carnet** : des notes d'équipe en Markdown,
  certaines publiques sur `/notes`, et un résumé hebdomadaire par e-mail.
- Les développeurs partent de `php bin/prime make:plugin mon-plugin` ([`plugin-starter.md`](plugin-starter.md),
  [`plugins.md`](plugins.md)).

## Accessibilité et clavier

L'administration vise le niveau WCAG 2.2 AA et se pilote entièrement au clavier :

- `Tab` / `Maj` + `Tab` parcourent les éléments ; un anneau corail montre l'élément actif ;
  le lien « Aller au contenu » est le premier arrêt de chaque page.
- `Échap` ferme les fenêtres, menus et panneaux, et rend le focus à l'élément qui les a ouverts.
- Dans les onglets et les grilles (médiathèque), les flèches déplacent la sélection.
- **Publier un article au clavier** : `Ctrl` + `K`, « nouvel article », `Entrée` ; taper le titre ;
  `Tab` jusqu'au corps, écrire ; `Tab` jusqu'à « Publier », `Entrée`.
- **Envoyer un média** : dans la médiathèque, `Tab` jusqu'à « Téléverser », `Entrée`, choisir le
  fichier ; le texte alternatif se remplit dans le panneau de détails.
- **Créer un jeton d'API** : Profil, onglet « Jetons d'API » (flèches entre onglets), nom,
  capacités (raccourcis « Lecture seule » / « Tout »), « Créer le jeton » ; copiez le jeton,
  il ne sera plus affiché.
- **Menu mobile d'Aurora** : `Tab` jusqu'au bouton menu, `Entrée` l'ouvre, `Échap` le ferme et
  revient au bouton.
