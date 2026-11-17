<?php

declare(strict_types=1);

/**
 * Gabarits et sujets des e-mails transactionnels (français).
 */
return [
    'automatic' => 'Cet e-mail a été envoyé automatiquement depuis :site, aucune réponse n’est nécessaire.',
    'button_fallback' => 'Le bouton ne fonctionne pas ? Copiez-collez ce lien dans votre navigateur :',
    'contact_admin' => 'Vous n’êtes pas à l’origine de cette action ? Contactez immédiatement un administrateur du site.',
    'content_review' => [
        'action' => 'Ouvrir dans l’éditeur',
        'intro' => ':author a soumis « :title » à la relecture.',
        'modified' => 'Dernière modification',
        'note' => 'Ajoutez une note si vous ne pouvez pas publier vous-même.',
        'subject' => '« :title » attend votre relecture',
        'title' => 'Contenu à relire',
        'type' => 'Type',
    ],
    'digest' => [
        'action' => 'Ouvrir le tableau de bord',
        'failed_jobs' => 'Tâches en échec : :count',
        'intro' => 'Voici l’activité des :days derniers jours sur :site.',
        'new_content' => 'Nouveaux contenus : :count',
        'pending_comments' => 'Commentaires à modérer : :count',
        'subject' => 'Résumé :period de votre site',
        'title' => 'Résumé de votre site',
    ],
    'expire_hours' => 'Ce lien est à usage unique et valable pendant :hours heures.',
    'expire_minutes' => 'Ce lien est à usage unique et expire dans :minutes minutes.',
    'greeting' => 'Bonjour :name,',
    'greeting_plain' => 'Bonjour :name,',
    'layout' => [
        'footer' => 'Envoyé par :site',
        'pied_de_page' => 'Cet e-mail a été envoyé automatiquement par :site. Si vous n’êtes pas à l’origine de cette action, vous pouvez ignorer ce message ou contacter votre administrateur.',
        'preheader_default' => 'Message automatique de :site',
        'preheader_password' => 'Sécurité de votre compte :site',
        'unsubscribe_hint' => 'Vous recevez cet e-mail car vous possédez un compte sur :site.',
    ],
    'password-changed' => [
        'autres_sessions_connexions_memorisees_ont' => 'Toutes vos autres sessions et connexions mémorisées ont été automatiquement révoquées par mesure de sécurité.',
        'mot_passe_ete_modifie' => 'Votre mot de passe a été modifié',
        'mot_passe_modifie_le' => 'Le mot de passe associé à votre compte sur :site a été modifié le :date (UTC).',
        'pas_a_l_origine' => '<strong>Vous n’êtes pas à l’origine de ce changement ?</strong> Contactez immédiatement un administrateur du site.',
    ],
    'password-reset' => [
        'choisir_nouveau_mot_passe' => 'Choisir un nouveau mot de passe',
        'demande_reinitialisation_mot_passe_ete' => 'Une demande de réinitialisation de mot de passe a été effectuée pour votre compte sur',
        'lien_usage_unique_minutes' => 'Ce lien est à usage unique et expire dans :minutes minutes.',
        'reinitialisation_mot_passe' => 'Réinitialisation de votre mot de passe',
        'si_bouton_ne_fonctionne_pas' => 'Si le bouton ne fonctionne pas, copiez-collez ce lien dans votre navigateur :',
    ],
    'password_changed' => [
        'intro' => 'Le mot de passe associé à votre compte sur :site a été modifié le :date (UTC).',
        'sessions' => 'Toutes vos autres sessions et connexions mémorisées ont été automatiquement révoquées par mesure de sécurité.',
        'subject' => 'Votre mot de passe a été modifié',
        'title' => 'Votre mot de passe a été modifié',
    ],
    'password_reset' => [
        'action' => 'Choisir un nouveau mot de passe',
        'ignore' => 'Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre mot de passe ne changera pas.',
        'intro' => 'Une demande de réinitialisation de mot de passe a été effectuée pour votre compte sur :site.',
        'subject' => 'Réinitialisation de votre mot de passe',
        'title' => 'Réinitialisation de votre mot de passe',
    ],
    'regards' => 'L’équipe de :site',
    'security_update' => [
        'action' => 'Sécuriser mon compte',
        'details' => 'Détails de la connexion',
        'device' => 'Appareil',
        'intro' => 'Votre compte :site vient d’être utilisé depuis un nouvel appareil.',
        'ip' => 'Adresse IP',
        'subject' => 'Nouvelle connexion à votre compte',
        'title' => 'Nouvelle connexion détectée',
        'unknown' => 'Cette connexion ne vient pas de vous ? Faites réinitialiser votre mot de passe immédiatement.',
        'when' => 'Date',
    ],
    'test' => [
        'intro' => 'Cet e-mail confirme que l’envoi depuis :site fonctionne correctement.',
        'sent_at' => 'Envoyé le :date',
        'subject' => 'E-mail de test de :site',
        'title' => 'Configuration e-mail opérationnelle',
        'transport' => 'Méthode d’envoi : :transport',
    ],
    'two_factor_reset' => [
        'action' => 'Ouvrir mon profil',
        'hint' => 'Configurez de nouveau une application d’authentification depuis votre profil pour protéger votre compte.',
        'intro' => 'La double authentification de votre compte sur :site vient d’être réinitialisée par un administrateur.',
        'subject' => 'Double authentification réinitialisée',
        'title' => 'Double authentification réinitialisée',
    ],
    'user-invite' => [
        'compte_cree' => 'Un administrateur vient de créer votre compte sur :site. Cliquez ci-dessous pour définir votre mot de passe et activer votre accès.',
        'definir_mon_mot_passe' => 'Définir mon mot de passe',
        'lien_invitation_heures' => 'Ce lien d’invitation est à usage unique et valable pendant :hours heures.',
        'votre_compte_pret' => 'Votre compte :site est prêt',
    ],
    'user_invite' => [
        'action' => 'Définir mon mot de passe',
        'intro' => 'Un administrateur vient de créer votre compte sur :site. Cliquez ci-dessous pour définir votre mot de passe et activer votre accès.',
        'subject' => 'Votre compte :site est prêt',
        'title' => 'Votre compte :site est prêt',
    ],
];
