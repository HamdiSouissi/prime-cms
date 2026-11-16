<?php

declare(strict_types=1);

/**
 * Transactional e-mail subjects and templates (English).
 */
return [
    'greeting' => 'Hello :name,',
    'greeting_plain' => 'Hello :name,',
    'regards' => 'The :site team',
    'button_fallback' => 'If the button does not work, copy and paste this link into your browser:',
    'automatic' => 'This e-mail was sent automatically by :site, no reply is needed.',
    'contact_admin' => 'Did not do this yourself? Contact a site administrator right away.',
    'expire_minutes' => 'This link can be used once and expires in :minutes minutes.',
    'expire_hours' => 'This link can be used once and is valid for :hours hours.',

    'password_reset' => [
        'subject' => 'Reset your password',
        'title' => 'Reset your password',
        'intro' => 'A password reset was requested for your account on :site.',
        'action' => 'Choose a new password',
        'ignore' => 'If you did not request this, ignore this e-mail: your password will not change.',
    ],

    'password_changed' => [
        'subject' => 'Your password has been changed',
        'title' => 'Your password has been changed',
        'intro' => 'The password for your account on :site was changed on :date (UTC).',
        'sessions' => 'For your security, all other sessions and remembered devices were revoked.',
    ],

    'user_invite' => [
        'subject' => 'Your :site account is ready',
        'title' => 'Your :site account is ready',
        'intro' => 'An administrator just created your account on :site. Click below to set your password and activate your access.',
        'action' => 'Set my password',
    ],

    'two_factor_reset' => [
        'subject' => 'Two-factor authentication reset',
        'title' => 'Two-factor authentication reset',
        'intro' => 'Two-factor authentication on your :site account was just reset by an administrator.',
        'hint' => 'Set up an authenticator app again from your profile to protect your account.',
        'action' => 'Open my profile',
    ],

    'security_update' => [
        'subject' => 'New sign-in to your account',
        'title' => 'New sign-in detected',
        'intro' => 'Your :site account was just used from a new device.',
        'details' => 'Sign-in details',
        'when' => 'Date',
        'device' => 'Device',
        'ip' => 'IP address',
        'unknown' => 'Is this not you? Reset your password immediately.',
        'action' => 'Secure my account',
    ],

    'content_review' => [
        'subject' => '“:title” is waiting for your review',
        'title' => 'Content to review',
        'intro' => ':author submitted “:title” for review.',
        'type' => 'Type',
        'modified' => 'Last updated',
        'action' => 'Open in the editor',
        'note' => 'Add a note if you cannot publish it yourself.',
    ],

    'test' => [
        'subject' => 'Test e-mail from :site',
        'title' => 'E-mail delivery works',
        'intro' => 'This message confirms that sending mail from :site works correctly.',
        'transport' => 'Transport: :transport',
        'sent_at' => 'Sent on :date',
    ],

    'digest' => [
        'subject' => ':period digest for your site',
        'title' => 'Your site digest',
        'intro' => 'Here is what happened on :site over the last :days days.',
        'new_content' => 'New content: :count',
        'pending_comments' => 'Comments to moderate: :count',
        'failed_jobs' => 'Failed tasks: :count',
        'action' => 'Open the dashboard',
    ],

    'layout' => [
        'preheader_password' => 'Security of your :site account',
        'preheader_default' => 'Automatic message from :site',
        'footer' => 'Sent by :site',
        'unsubscribe_hint' => 'You receive this e-mail because you have an account on :site.',
    ],
];
