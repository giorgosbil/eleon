<?php declare(strict_types=1);
/**
 * Telegram: each person connects their own Telegram account (their phone
 * number) and chats with their Telegram contacts next to their emails: all
 * their private chats, new chats, photos and files both ways. Only they see
 * it. A background process (bin/daemon.php, the "telegram" container) stays
 * signed in (lib/telegram.php, lib/sync.php). The manifest format is
 * described in lib/modules.php of the core.
 */
return [
    'id'       => 'telegram',
    'name'     => 'Telegram',
    'package'  => 'pro',
    'features' => [
        'telegram' => ['Telegram', 'Telegram tab: each person connects their own Telegram account (phone number) and chats with their contacts here, files included. Only they see their chats.'],
    ],
    'tables' => [
        'tg_accounts' => 'ecw_tg_accounts',
        'tg_contacts' => 'ecw_tg_contacts',
        'tg_chats'    => 'ecw_tg_chats',
        'tg_messages' => 'ecw_tg_messages',
        'tg_jobs'     => 'ecw_tg_jobs',
    ],
    'migrations' => true,
    // In backups and DataPush: the photos and files of the chats. Never storage/telegram/sessions:
    // a sign-in used on two computers at once is ended by Telegram.
    'backup_dirs' => ['telegram/media', 'telegram/out'],
    'api' => [
        'file' => 'api.php',
        'actions' => [
            'tg_status'         => ['feature' => 'telegram'],
            'tg_chats'          => ['feature' => 'telegram'],
            'tg_messages'       => ['feature' => 'telegram'],
            'tg_contacts'       => ['feature' => 'telegram'],
            'tg_job'            => ['feature' => 'telegram'],
            'tg_media'          => ['feature' => 'telegram'],
            'tg_login_start'    => ['feature' => 'telegram', 'post' => true],
            'tg_login_code'     => ['feature' => 'telegram', 'post' => true],
            'tg_login_password' => ['feature' => 'telegram', 'post' => true],
            'tg_disconnect'     => ['feature' => 'telegram', 'post' => true],
            'tg_send'           => ['feature' => 'telegram', 'post' => true],
            'tg_open_contact'   => ['feature' => 'telegram', 'post' => true],
            'tg_new_number'     => ['feature' => 'telegram', 'post' => true],
        ],
    ],
    'pages' => [
        'telegram' => 'web/telegram.php',
    ],
    'nav' => [
        'telegram' => ['label' => 'Telegram', 'icon' => 'fa-brands fa-telegram', 'page' => 'telegram', 'feature' => 'telegram', 'after' => 'whatsapp'],
    ],
    // The tab's icon colour in the navigation, and the tab's look.
    'everywhere' => ['feature' => 'telegram', 'css' => ['telegram.css']],
    'hooks' => [
        'case.event_label' => [['lib/telegram.php', 'ecw_tg_event_label']],
    ],
];
