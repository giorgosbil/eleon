<?php declare(strict_types=1);
/**
 * Chats in cases: link chosen chat messages (Teams, WhatsApp, Telegram) to a
 * case. In any chat, "Link to case" lets the person select messages (click,
 * Shift+click, or drag a box), press Done and choose the case. On the case
 * each chat is a column of the timeline next to the email threads (one row
 * per burst of messages) that can follow the chat (new messages added) and be
 * answered from the case; the AI reads them with the case's emails,
 * and (with chat_links_ai) the AI suggests, while a chat is open, which case
 * its new messages belong to (lib/chatlink.php). The manifest format is
 * described in lib/modules.php of the core.
 */
return [
    'id'       => 'chatlink',
    'name'     => 'Chats in cases',
    'package'  => 'pro',
    'features' => [
        'chat_links'    => ['Chats in cases', 'Link chosen Teams, WhatsApp and Telegram messages to a case: select them in the chat and choose the case. On the case each chat is a column next to the email threads, in time order, and the AI reads them with the emails.'],
        'chat_links_ai' => ['Chats in cases: AI suggestions', 'While a chat is open, the AI suggests which case its new messages belong to, ready to link with one click. Needs the AI assistant.'],
    ],
    'tables' => [
        'chat_links'            => 'ecw_chat_links',
        'chat_link_columns'     => 'ecw_chat_link_columns',
        'chat_link_suggestions' => 'ecw_chat_link_suggestions',
    ],
    'migrations' => true,
    'api' => [
        'file' => 'api.php',
        'actions' => [
            'chatlink_marks'         => ['feature' => 'chat_links', 'read_only' => true],
            'chatlink_link'          => ['feature' => 'chat_links', 'post' => true],
            'chatlink_unlink'        => ['feature' => 'chat_links', 'post' => true],
            'chatlink_column_update' => ['feature' => 'chat_links', 'post' => true, 'case_scoped' => true],
            'chatlink_column_unlink' => ['feature' => 'chat_links', 'post' => true, 'case_scoped' => true],
            'chatlink_column_reply'  => ['feature' => 'chat_links', 'post' => true, 'case_scoped' => true],
            'chatlink_suggest'       => ['feature' => 'chat_links_ai', 'post' => true],
            'chatlink_dismiss'       => ['feature' => 'chat_links_ai', 'post' => true],
        ],
    ],
    // Selecting messages works in every chat (the Teams tab, WhatsApp, Telegram); on the case it draws the chat columns' rows.
    'everywhere' => ['feature' => 'chat_links', 'css' => ['chatlink.css'], 'js' => ['chatlink.js']],
    'hooks' => [
        'case.event_label'      => [['lib/chatlink.php', 'ecw_chatlink_event_label']],
        'case.chat_material'    => [['lib/chatlink.php', 'ecw_chatlink_material']],
        'case.timeline_columns' => [['lib/chatlink.php', 'ecw_chatlink_columns']],
        'case.timeline_events'  => [['lib/chatlink.php', 'ecw_chatlink_events']],
        'chat.message_in'       => [['lib/chatlink.php', 'ecw_chatlink_message_in']],
        'case.merged'           => [['lib/chatlink.php', 'ecw_chatlink_case_merged']],
        'sync.tick'             => [['lib/chatlink.php', 'ecw_chatlink_tick']],
    ],
];
