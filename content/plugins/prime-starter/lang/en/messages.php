<?php

declare(strict_types=1);

/**
 * Prime Starter plugin translations (English).
 */
return [
    'blocks' => [
        'markdown' => [
            'description' => 'Text written in Markdown, turned into clean HTML when displayed.',
            'title' => 'Markdown',
        ],
    ],
    'capability' => 'Manage the notebook (Prime Starter)',
    'colors' => [
        'mint' => 'Mint',
        'rose' => 'Rose',
        'sand' => 'Sand',
        'sky' => 'Sky',
    ],
    'dynamic' => [
        'default_title' => 'Latest notes',
        'description' => 'Lists the latest public notes of the notebook.',
        'label' => 'Notebook notes (Prime Starter)',
        'limit' => 'Number of notes (1 to 10)',
        'title' => 'Title',
    ],
    'editor' => [
        'help' => 'Headings with #, lists with -, **bold**, *italic*, `code` and [links](https://example.com).',
        'label' => 'Content (Markdown)',
    ],
    'fields' => [
        'body' => 'Content',
        'body_help' => 'Markdown accepted: # heading, - list, **bold**, *italic*, [link](https://…).',
        'color' => 'Colour',
        'pinned' => 'Pinned',
        'pinned_help' => 'Pinned notes come first.',
        'private' => 'Private',
        'public' => 'Public',
        'public_help' => 'Shown on the public notebook page and in the API without authentication.',
        'title' => 'Title',
        'updated_at' => 'Updated',
        'visibility' => 'Visibility',
    ],
    'mail' => [
        'action' => 'Open the notebook',
        'intro' => ':count note changed in the last :days days on :site.|:count notes changed in the last :days days on :site.',
        'subject' => 'Notebook: :count note changed this week|Notebook: :count notes changed this week',
        'title' => 'Your notebook this week',
    ],
    'manifest' => [
        'description' => 'Starter plugin: a notebook that shows every extension point (admin, settings, public page, API, blocks, editor, tasks, e-mail).',
    ],
    'menu' => 'Notebook',
    'notices' => [
        'welcome' => [
            'action' => 'Open the notebook',
            'message' => 'Prime Starter is active: write a first note, then copy the plugin with bin/prime make:plugin.',
            'title' => 'Notebook ready',
        ],
    ],
    'public' => [
        'description' => ':count public note in the notebook.|:count public notes in the notebook.',
        'disabled' => 'This page is not available.',
        'empty' => 'No public notes yet.',
    ],
    'scheduler' => [
        'digest' => 'Weekly notebook digest by e-mail (when an address is set).',
    ],
    'settings' => [
        'digest_email' => 'Weekly digest e-mail',
        'digest_email_help' => 'Every Monday, the notes changed during the week are sent to this address. Empty: nothing is sent.',
        'markdown_posts' => 'Write posts in Markdown',
        'markdown_posts_help' => 'Replaces Prime Editor with a Markdown editor on posts.',
        'public_enabled' => 'Public notebook page',
        'public_enabled_help' => 'Publishes the notes marked “Public” at /notes.',
        'public_title' => 'Public page title',
        'public_title_help' => 'Shown at the top of /notes and in the browser tab.',
        'section_description' => 'Public page, Markdown editor and e-mail digest of the Prime Starter plugin.',
        'section_title' => 'Notebook',
    ],
    'system' => [
        'label' => 'Notebook (Prime Starter)',
        'table_missing' => 'Notes table not found: reactivate the plugin.',
        'value' => ':count note|:count notes',
    ],
    'ui' => [
        'actions' => 'Actions',
        'confirm_delete' => 'Delete the note “:title”? This cannot be undone.',
        'create' => 'Create note',
        'created' => 'Note created.',
        'delete_named' => 'Delete “:title”',
        'deleted' => 'Note “:title” deleted.',
        'edit' => 'Edit note',
        'edit_named' => 'Edit “:title”',
        'empty_description' => 'Notes are memos for the team; make some public to show them on the site.',
        'empty_title' => 'No notes yet',
        'missing' => 'This note no longer exists.',
        'new' => 'New note',
        'save' => 'Save',
        'subtitle' => 'Team notes, in Markdown. Public notes appear on /notes.',
        'table_caption' => 'Notebook notes',
        'title' => 'Notebook',
        'updated' => 'Note saved.',
        'view_public' => 'View public page',
    ],
    'validation' => [
        'body_max' => 'The content is longer than :max characters.',
        'summary' => 'The note was not saved.',
        'title_max' => 'The title is longer than :max characters.',
        'title_required' => 'Give the note a title.',
    ],
];
