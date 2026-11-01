<?php declare(strict_types=1);
/**
 * Email Case Workspace — physical table names.
 *
 * The data model step names its tables `users`, `cases`, `organizations`,
 * ... but this schema shares registerdatabase_dev with the Zetisis platform,
 * where `users` already exists as a different table. Every table is therefore
 * prefixed `ecw_` — see the header of
 * database/migrations/20260917_ecw_001_core_schema.sql for the full reasoning.
 *
 * Nothing outside this file may hardcode a physical table name. Route every
 * query through ECW_T or ecw_table(), so that moving this schema into its own
 * database (where the spec names could be used verbatim) stays a one-file
 * change.
 */

/** spec name => physical name. The mapping is mechanical and 1:1. */
const ECW_T = [
    'organizations'      => 'ecw_organizations',
    'users'              => 'ecw_users',
    'user_invitations'   => 'ecw_user_invitations',
    'organization_users' => 'ecw_organization_users',
    'mailboxes'          => 'ecw_mailboxes',
    'mail_folders'       => 'ecw_mail_folders',
    'email_addresses'    => 'ecw_email_addresses',
    'email_threads'      => 'ecw_email_threads',
    'email_messages'     => 'ecw_email_messages',
    'email_attachments'  => 'ecw_email_attachments',
    'cases'              => 'ecw_cases',
    'case_threads'       => 'ecw_case_threads',
    'case_events'        => 'ecw_case_events',
    'mailbox_sync_state' => 'ecw_mailbox_sync_state',
    // step 02 — sync and OAuth infrastructure
    'graph_subscriptions' => 'ecw_graph_subscriptions',
    'sync_queue'          => 'ecw_sync_queue',
    'message_dead_letters' => 'ecw_message_dead_letters',
    // step 03 — inbox, search, composer
    'message_participants' => 'ecw_message_participants',
    'drafts'               => 'ecw_drafts',
    'signatures'           => 'ecw_signatures',
    // step 05 — rate limiting
    'rate_limits'           => 'ecw_rate_limits',
    // case groups and per-case sharing
    'case_groups'           => 'ecw_case_groups',
    'case_group_members'    => 'ecw_case_group_members',
    'case_group_feedback'   => 'ecw_case_group_feedback',
    'saved_filters'         => 'ecw_saved_filters',
    'case_members'          => 'ecw_case_members',
    // collaboration (migration 013)
    'case_notes'            => 'ecw_case_notes',
    'notifications'         => 'ecw_notifications',
    // account safety (migration 020)
    'password_resets'       => 'ecw_password_resets',
    'trusted_devices'       => 'ecw_trusted_devices',   // migration 022
    'note_reads'            => 'ecw_note_reads',        // migration 024
    'rule_runs'             => 'ecw_rule_runs',         // migration 027
    'rule_backfills'        => 'ecw_rule_backfills',    // migration 052
    'case_reviews'          => 'ecw_case_reviews',      // migration 053
    'deal_docs'             => 'ecw_deal_docs',         // migration 054
    'deal_state'            => 'ecw_deal_state',        // migration 054
    'companies'             => 'ecw_companies',         // migration 028
    'ai_tokens'             => 'ecw_ai_tokens',         // migration 034
    'audit_log'             => 'ecw_audit_log',         // migration 035
    'agent_runs'            => 'ecw_agent_runs',        // migration 037
    'ai_suggestions'        => 'ecw_ai_suggestions',    // migration 037
    'case_ai_summaries'     => 'ecw_case_ai_summaries', // migration 037
    'mailbox_members'       => 'ecw_mailbox_members',   // migration 039
    'case_checklist'        => 'ecw_case_checklist',    // migration 040
    'case_templates'        => 'ecw_case_templates',    // migration 040
    'case_refs'             => 'ecw_case_refs',         // migration 042
    'error_reports'         => 'ecw_error_reports',     // migration 043
    'ai_usage'              => 'ecw_ai_usage',          // migration 044
    'case_resolutions'      => 'ecw_case_resolutions',  // migration 046
    'case_similar'          => 'ecw_case_similar',      // migration 046
    'message_translations'  => 'ecw_message_translations', // migration 047
    // mail and case extras (migration 021)
    'blocked_senders'       => 'ecw_blocked_senders',
    'case_tags'             => 'ecw_case_tags',
    'push_subscriptions'    => 'ecw_push_subscriptions',
    'app_settings'          => 'ecw_app_settings',
    'followups'             => 'ecw_followups',
    'templates'             => 'ecw_templates',
    'rules'                 => 'ecw_rules',
    'presence'              => 'ecw_presence',
    'case_teams_chats'      => 'ecw_case_teams_chats',
    'case_teams_messages'   => 'ecw_case_teams_messages',
];

/** ecw_cases.visibility values. */
const ECW_CASE_VISIBILITIES = ['organization', 'members'];

/**
 * Resolve a spec table name to its physical name.
 *
 * Throws rather than returning the input unchanged: a typo that silently
 * produced `SELECT ... FROM case_thread` would hit the platform's namespace,
 * not ours.
 */
function ecw_table(string $specName): string
{
    if (isset(ECW_T[$specName])) {
        return ECW_T[$specName];
    }
    // Tables of optional modules (lib/modules.php).
    static $moduleTables = null;
    if ($moduleTables === null) {
        require_once __DIR__ . '/modules.php';
        $moduleTables = ecw_module_tables();
    }
    if (!isset($moduleTables[$specName])) {
        throw new InvalidArgumentException("Unknown table: {$specName}");
    }
    return $moduleTables[$specName];
}

/** Columns safe to SELECT for list views — never the LONGTEXT body columns. */
const ECW_MESSAGE_LIST_COLUMNS = [
    'id', 'mailbox_id', 'email_thread_id', 'mail_folder_id',
    'provider_message_id', 'internet_message_id',
    'subject', 'from_email', 'from_name', 'to_json', 'cc_json',
    'direction', 'is_read', 'has_attachments',
    'sent_at', 'received_at', 'occurred_at',
];

/** Vocabularies. VARCHAR columns, not ENUMs — these grow without a migration. */
const ECW_ROLES            = ['admin', 'user', 'read_only'];
const ECW_CASE_STATUSES    = ['open', 'waiting', 'closed'];
const ECW_FOLDER_TYPES     = ['inbox', 'sent', 'drafts', 'deleted', 'archive', 'junk', 'snoozed', 'custom', 'system'];
/** Folders never synced or shown: Outlook's own bookkeeping (Sync Issues, Conflicts, Outbox…). */
const ECW_FOLDER_TYPES_HIDDEN = ['system'];
/** Folders synced and shown, but never read by the AI agents. */
const ECW_FOLDER_TYPES_NOT_FOR_AGENTS = ['junk', 'deleted', 'drafts', 'system'];
/** The step 02 vocabulary: exactly the states the UI has to be able to show. */
const ECW_MAILBOX_STATUSES = ['pending', 'connected', 'syncing', 'needs_reauth', 'temp_error', 'disconnected'];
const ECW_SYNC_JOB_TYPES   = ['folders', 'delta', 'backfill', 'bodies'];
const ECW_SYNC_JOB_STATUS  = ['queued', 'running', 'done', 'failed'];
const ECW_DIRECTIONS       = ['incoming', 'outgoing', 'draft'];
const ECW_BODY_STATES      = ['not_fetched', 'metadata_only', 'fetched', 'fetch_failed'];
const ECW_DOWNLOAD_STATES  = ['not_downloaded', 'downloading', 'downloaded', 'skipped_too_large', 'failed'];

/** case_events.event_type values written so far. Free text: extend freely. */
const ECW_EVENT_TYPES = [
    'CASE_CREATED', 'CASE_RENAMED', 'CASE_STATUS_CHANGED', 'CASE_DELETED', 'CASE_RESTORED',
    'THREAD_LINKED', 'THREAD_UNLINKED', 'CASE_SHARING_CHANGED',
    'NOTE_ADDED', 'CASE_REOPENED', 'TEAMS_CHAT_LINKED', 'TEAMS_CHAT_UNLINKED',
    'EMAIL_SENT', 'EMAIL_RECEIVED',
];
