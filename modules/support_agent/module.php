<?php declare(strict_types=1);
/**
 * Support agent: admins send a conversation or a whole case to the support agent
 * (a separate program, C:\xampp\htdocs\support_agent), which reads it, drafts what it
 * teaches (the problem from the customer's emails, the solution from ours) and learns
 * it only after an admin approves it there. A small button on every thread and case,
 * shown to admins only, once Settings → Support agent is connected.
 * The manifest format is described in lib/modules.php of the core.
 */
return [
    'id'       => 'support_agent',
    'name'     => 'Support agent',
    'package'  => 'pro',
    'features' => [
        'support_agent' => ['Support agent', 'Admins send a conversation or a case to the support agent with one small button. It drafts the problem and how it was solved, and learns it after an admin approves it there.'],
    ],
    'migrations' => true,
    'api' => [
        'file' => 'api.php',
        'actions' => [
            'sa_state'       => ['feature' => 'support_agent', 'read_only' => true],
            'sa_send_thread' => ['feature' => 'support_agent', 'post' => true],
            'sa_send_case'   => ['feature' => 'support_agent', 'post' => true, 'case_scoped' => true],
            'sa_settings_save' => ['feature' => 'support_agent', 'post' => true],
        ],
    ],
    'settings' => [
        'support_agent' => ['file' => 'web/settings.php', 'icon' => 'fa-robot', 'label' => 'Support agent', 'feature' => 'support_agent', 'admin' => true],
    ],
    // The button on threads and cases; the script asks sa_state first and shows nothing to non-admins.
    'everywhere' => ['feature' => 'support_agent', 'css' => ['support_agent.css'], 'js' => ['support_agent.js']],
];
