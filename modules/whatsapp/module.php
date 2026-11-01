<?php declare(strict_types=1);
/**
 * WhatsApp: chat with customers on the workspace's WhatsApp Business number,
 * next to their emails (lib/whatsapp.php). The manifest format is described
 * in lib/modules.php of the core.
 */
return [
    'id'       => 'whatsapp',
    'name'     => 'WhatsApp',
    'package'  => 'pro',
    'features' => [
        'whatsapp' => ['WhatsApp', 'WhatsApp tab: chat with customers on your WhatsApp Business number, send approved templates, link a chat to its case.'],
    ],
    'tables' => [
        'wa_chats'    => 'ecw_wa_chats',
        'wa_messages' => 'ecw_wa_messages',
    ],
    'migrations' => true,
    'api' => [
        'file' => 'api.php',
        'actions' => [
            'wa_chats'         => ['feature' => 'whatsapp'],
            'wa_messages'      => ['feature' => 'whatsapp'],
            'wa_templates'     => ['feature' => 'whatsapp'],
            'wa_media'         => ['feature' => 'whatsapp'],
            'wa_send'          => ['feature' => 'whatsapp', 'post' => true],
            'wa_send_template' => ['feature' => 'whatsapp', 'post' => true],
            'wa_settings_save' => ['feature' => 'whatsapp', 'post' => true],
            'wa_disconnect'    => ['feature' => 'whatsapp', 'post' => true],
        ],
    ],
    'pages' => [
        'whatsapp' => 'web/whatsapp.php',
        'webhook'  => 'web/webhook.php',
    ],
    'settings' => [
        'whatsapp' => ['file' => 'web/settings.php', 'icon' => 'fa-comments', 'label' => 'WhatsApp', 'feature' => 'whatsapp', 'admin' => true],
    ],
    'nav' => [
        // The number belongs to one account, or to all of them (Settings → WhatsApp).
        'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'fa-brands fa-whatsapp', 'page' => 'whatsapp', 'feature' => 'whatsapp', 'after' => 'teams',
                       'show' => ['lib/whatsapp.php', 'ecw_wa_nav_visible']],
    ],
    // The tab's icon colour in the navigation, and the tab's and settings page's look.
    'everywhere' => ['feature' => 'whatsapp', 'css' => ['whatsapp.css']],
    'hooks' => [
        'case.event_label' => [['lib/whatsapp.php', 'ecw_wa_event_label']],
    ],
];
