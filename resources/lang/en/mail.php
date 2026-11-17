<?php

declare(strict_types=1);

/**
 * Transactional e-mail subjects and templates (English).
 */
return [
    'automatic' => 'This e-mail was sent automatically by :site, no reply is needed.',
    'button_fallback' => 'If the button does not work, copy and paste this link into your browser:',
    'contact_admin' => 'Did not do this yourself? Contact a site administrator right away.',
    'content_review' => [
        'action' => 'Open in the editor',
        'intro' => ':author submitted “:title” for review.',
        'modified' => 'Last updated',
        'note' => 'Add a note if you cannot publish it yourself.',
        'subject' => '“:title” is waiting for your review',
        'title' => 'Content to review',
        'type' => 'Type',
    ],
    'digest' => [
        'action' => 'Open the dashboard',
        'failed_jobs' => 'Failed tasks: :count',
        'intro' => 'Here is what happened on :site over the last :days days.',
        'new_content' => 'New content: :count',
        'pending_comments' => 'Comments to moderate: :count',
        'subject' => ':period digest for your site',
        'title' => 'Your site digest',
    ],
    'expire_hours' => 'This link can be used once and is valid for :hours hours.',
    'expire_minutes' => 'This link can be used once and expires in :minutes minutes.',
    'greeting' => 'Hello :name,',
    'greeting_plain' => 'Hello :name,',
    'layout' => [
        'footer' => 'Sent by :site',
        'pied_de_page' => 'This e-mail was sent automatically by :site. If you did not request this action, you can ignore this message or contact your administrator.',
        'preheader_default' => 'Automatic message from :site',
        'preheader_password' => 'Security of your :site account',
        'unsubscribe_hint' => 'You receive this e-mail because you have an account on :site.',
    ],
    'password-changed' => [
        'autres_sessions_connexions_memorisees_ont' => 'All your other sessions and remembered sign-ins were automatically revoked as a security measure.',
        'mot_passe_ete_modifie' => 'Your password has been changed',
        'mot_passe_modifie_le' => 'The password associated with your account on :site was changed on :date (UTC).',
        'pas_a_l_origine' => '<strong>Did you not request this change?</strong> Contact a site administrator immediately.',
    ],
    'password-reset' => [
        'choisir_nouveau_mot_passe' => 'Choose a new password',
        'demande_reinitialisation_mot_passe_ete' => 'A password reset request was made for your account on',
        'lien_usage_unique_minutes' => 'This link is single-use and expires in :minutes minutes.',
        'reinitialisation_mot_passe' => 'Reset your password',
        'si_bouton_ne_fonctionne_pas' => 'If the button does not work, copy and paste this link into your browser:',
    ],
    'password_changed' => [
        'intro' => 'The password for your account on :site was changed on :date (UTC).',
        'sessions' => 'For your security, all other sessions and remembered devices were revoked.',
        'subject' => 'Your password has been changed',
        'title' => 'Your password has been changed',
    ],
    'password_reset' => [
        'action' => 'Choose a new password',
        'ignore' => 'If you did not request this, ignore this e-mail: your password will not change.',
        'intro' => 'A password reset was requested for your account on :site.',
        'subject' => 'Reset your password',
        'title' => 'Reset your password',
    ],
    'regards' => 'The :site team',
    'security_update' => [
        'action' => 'Secure my account',
        'details' => 'Sign-in details',
        'device' => 'Device',
        'intro' => 'Your :site account was just used from a new device.',
        'ip' => 'IP address',
        'subject' => 'New sign-in to your account',
        'title' => 'New sign-in detected',
        'unknown' => 'Is this not you? Reset your password immediately.',
        'when' => 'Date',
    ],
    'test' => [
        'intro' => 'This message confirms that sending mail from :site works correctly.',
        'sent_at' => 'Sent on :date',
        'subject' => 'Test e-mail from :site',
        'title' => 'E-mail delivery works',
        'transport' => 'Transport: :transport',
    ],
    'two_factor_reset' => [
        'action' => 'Open my profile',
        'hint' => 'Set up an authenticator app again from your profile to protect your account.',
        'intro' => 'Two-factor authentication on your :site account was just reset by an administrator.',
        'subject' => 'Two-factor authentication reset',
        'title' => 'Two-factor authentication reset',
    ],
    'user-invite' => [
        'compte_cree' => 'An administrator just created your account on :site. Click below to set your password and activate your access.',
        'definir_mon_mot_passe' => 'Set my password',
        'lien_invitation_heures' => 'This invitation link is single-use and valid for :hours hours.',
        'votre_compte_pret' => 'Your :site account is ready',
    ],
    'user_invite' => [
        'action' => 'Set my password',
        'intro' => 'An administrator just created your account on :site. Click below to set your password and activate your access.',
        'subject' => 'Your :site account is ready',
        'title' => 'Your :site account is ready',
    ],
];
