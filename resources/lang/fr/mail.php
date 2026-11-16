<?php

declare(strict_types=1);

/**
 * Gabarits et sujets des e-mails transactionnels (français).
 */
return [
    'greeting' => 'Bonjour :name,',
    'greeting_plain' => 'Bonjour :name,',
    'regards' => 'L’équipe de :site',
    'button_fallback' => 'Le bouton ne fonctionne pas ? Copiez-collez ce lien dans votre navigateur :',
    'automatic' => 'Cet e-mail a été envoyé automatiquement depuis :site, aucune réponse n’est nécessaire.',
    'contact_admin' => 'Vous n’êtes pas à l’origine de cette action ? Contactez immédiatement un administrateur du site.',
    'expire_minutes' => 'Ce lien est à usage unique et expire dans :minutes minutes.',
    'expire_hours' => 'Ce lien est à usage unique et valable pendant :hours heures.',

    'password_reset' => [
        'subject' => 'Réinitialisation de votre mot de passe',
        'title' => 'Réinitialisation de votre mot de passe',
        'intro' => 'Une demande de réinitialisation de mot de passe a été effectuée pour votre compte sur :site.',
        'action' => 'Choisir un nouveau mot de passe',
        'ignore' => 'Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre mot de passe ne changera pas.',
    ],

    'password_changed' => [
        'subject' => 'Votre mot de passe a été modifié',
        'title' => 'Votre mot de passe a été modifié',
        'intro' => 'Le mot de passe associé à votre compte sur :site a été modifié le :date (UTC).',
        'sessions' => 'Toutes vos autres sessions et connexions mémorisées ont été automatiquement révoquées par mesure de sécurité.',
    ],

    'user_invite' => [
        'subject' => 'Votre compte :site est prêt',
        'title' => 'Votre compte :site est prêt',
        'intro' => 'Un administrateur vient de créer votre compte sur :site. Cliquez ci-dessous pour définir votre mot de passe et activer votre accès.',
        'action' => 'Définir mon mot de passe',
    ],

    'two_factor_reset' => [
        'subject' => 'Double authentification réinitialisée',
        'title' => 'Double authentification réinitialisée',
        'intro' => 'La double authentification de votre compte sur :site vient d’être réinitialisée par un administrateur.',
        'hint' => 'Configurez de nouveau une application d’authentification depuis votre profil pour protéger votre compte.',
        'action' => 'Ouvrir mon profil',
    ],

    'security_update' => [
        'subject' => 'Nouvelle connexion à votre compte',
        'title' => 'Nouvelle connexion détectée',
        'intro' => 'Votre compte :site vient d’être utilisé depuis un nouvel appareil.',
        'details' => 'Détails de la connexion',
        'when' => 'Date',
        'device' => 'Appareil',
        'ip' => 'Adresse IP',
        'unknown' => 'Cette connexion ne vient pas de vous ? Faites réinitialiser votre mot de passe immédiatement.',
        'action' => 'Sécuriser mon compte',
    ],

    'content_review' => [
        'subject' => '« :title » attend votre relecture',
        'title' => 'Contenu à relire',
        'intro' => ':author a soumis « :title » à la relecture.',
        'type' => 'Type',
        'modified' => 'Dernière modification',
        'action' => 'Ouvrir dans l’éditeur',
        'note' => 'Ajoutez une note si vous ne pouvez pas publier vous-même.',
    ],

    'test' => [
        'subject' => 'E-mail de test de :site',
        'title' => 'Configuration e-mail opérationnelle',
        'intro' => 'Cet e-mail confirme que l’envoi depuis :site fonctionne correctement.',
        'transport' => 'Méthode d’envoi : :transport',
        'sent_at' => 'Envoyé le :date',
    ],

    'digest' => [
        'subject' => 'Résumé :period de votre site',
        'title' => 'Résumé de votre site',
        'intro' => 'Voici l’activité des :days derniers jours sur :site.',
        'new_content' => 'Nouveaux contenus : :count',
        'pending_comments' => 'Commentaires à modérer : :count',
        'failed_jobs' => 'Tâches en échec : :count',
        'action' => 'Ouvrir le tableau de bord',
    ],

    'layout' => [
        'preheader_password' => 'Sécurité de votre compte :site',
        'preheader_default' => 'Message automatique de :site',
        'footer' => 'Envoyé par :site',
        'unsubscribe_hint' => 'Vous recevez cet e-mail car vous possédez un compte sur :site.',
    ],
];
