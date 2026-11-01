<?php declare(strict_types=1);
/**
 * Email Case Workspace — provider interface.
 *
 * V1 ships MicrosoftGraphProvider. Gmail and IMAP come later, so everything
 * above this interface — sync, ingest, cases, UI — must speak only in the
 * normalized shapes defined here, never in Graph's own JSON.
 *
 * WHAT IS DELIBERATELY NOT IN THIS INTERFACE
 * ------------------------------------------
 * Delta/change-token semantics are exposed as an opaque `cursor` string and
 * a `needs_full_resync` flag. IMAP has no delta at all and will implement the
 * same two methods with UIDVALIDITY/UIDNEXT underneath; Gmail has historyId.
 * If the cursor were typed as a Graph deltaLink, every caller would have to
 * learn the next provider's model too.
 *
 * NORMALIZED SHAPES (plain arrays, so they cross the DB boundary unchanged)
 * ------------------------------------------------------------------------
 * Tokens:  ['access_token','refresh_token','expires_at','scope',
 *           'account_id','tenant_id','email','display_name']
 *          expires_at is a UTC 'Y-m-d H:i:s' string, never a duration.
 *
 * Folder:  ['provider_folder_id','parent_provider_folder_id','name',
 *           'folder_type','is_system']
 *          folder_type uses OUR vocabulary (inbox/sent/drafts/deleted/
 *          archive/custom), not the provider's.
 *
 * Message: ['provider_message_id','internet_message_id','in_reply_to_message_id',
 *           'references'[],'provider_conversation_id','subject',
 *           'from_email','from_name','to'[],'cc'[],'bcc'[],
 *           'sent_at','received_at','provider_created_at','provider_modified_at',
 *           'is_read','has_attachments','web_link',
 *           'body_html','body_text','attachments'[],'removed']
 *          Recipients are ['email'=>..,'name'=>..]. Timestamps are UTC
 *          'Y-m-d H:i:s' or null. `removed` true means the provider reported
 *          the message deleted or moved out — everything but the id may be absent.
 *          Optional 'is_sent' (true = the user sent it), for providers where one
 *          message can sit in several folders at once (Gmail labels).
 *
 * DeltaPage: ['messages'[],'next_link','cursor','needs_full_resync','resync_url']
 *          Exactly one of next_link (more pages this round) or cursor (round
 *          complete, store this) is non-null on a successful page.
 */
interface MailProvider
{
    public function key(): string;

    // --- OAuth -----------------------------------------------------------
    public function authorizationUrl(string $state, string $redirectUri): string;
    public function exchangeCode(string $code, string $redirectUri): array;
    public function refreshTokens(string $refreshToken): array;

    /** Identity of the signed-in account, for the mailbox row. */
    public function account(): array;

    // --- Folders ---------------------------------------------------------
    /** @return array<int,array> every folder, flat, parents identified by provider id */
    public function listFolders(): array;

    // --- Messages --------------------------------------------------------
    /**
     * One page of an incremental sync round.
     *
     * @param string|null $cursor null starts a fresh round for the folder
     * @param string|null $nextLink continue the current round, verbatim
     */
    public function deltaPage(string $providerFolderId, ?string $cursor, ?string $nextLink, int $pageSize): array;

    /**
     * Newest-first page, for the initial import and the historical backfill.
     * Delta does not guarantee order, so it cannot serve "newest first".
     *
     * @return array{messages: array, next_link: ?string}
     */
    public function listMessages(string $providerFolderId, ?string $nextLink, int $pageSize, ?string $receivedBefore = null): array;

    /** Full message including body, for lazy body loading. */
    public function getMessage(string $providerMessageId): array;

    public function listAttachments(string $providerMessageId): array;
    public function downloadAttachment(string $providerMessageId, string $providerAttachmentId): string;

    // --- Mutations -------------------------------------------------------
    public function sendMessage(array $draft): array;
    /** $fromProviderFolderId: where it is now, when known (Gmail needs it to take the old label off). */
    public function moveMessage(string $providerMessageId, string $providerFolderId, ?string $fromProviderFolderId = null): array;
    /** Delete for good (used from Deleted Items / Trash). Throws InvalidArgumentException when the provider cannot. */
    public function deleteMessage(string $providerMessageId): void;
    public function markRead(string $providerMessageId, bool $isRead): void;
    /** Outlook flag / Gmail star. */
    public function setFlag(string $providerMessageId, bool $flagged): void;
    /** Outlook categories / Gmail labels "Case/…": add these, take off those; others stay. */
    public function setCategories(string $providerMessageId, array $add, array $remove): void;

    // Drafts in the mailbox's own Drafts folder (kept in step with the app's drafts).
    /**
     * Create or update a draft. $draft: subject, body_html, to/cc/bcc, reply_to_provider_message_id,
     * attachments [{local_id, filename, mime_type, content_bytes, provider_attachment_id?}].
     * @return array{id:string, attachments:array<string,string>} provider draft id, local id => provider attachment id
     */
    public function saveDraft(array $draft, ?string $providerDraftId): array;
    public function deleteDraft(string $providerDraftId): void;
    /**
     * Optional: send_at (UTC, when the mailbox will send a scheduled email), conversation_id, web_link.
     * @return array<int,array{id:string,subject:string,to:array,updated_at:?string,preview:string,has_attachments:bool}>
     */
    public function listDrafts(int $limit = 25): array;
    /** @return array{subject:string,to:array,cc:array,bcc:array,body_html:string,attachments:array<int,array{filename:string,mime_type:string,bytes:string,provider_attachment_id:string}>} */
    public function getDraft(string $providerDraftId): array;

    // Meeting invitations (throws when the provider cannot do it).
    /** @return array{event_id:string,subject:string,start:?string,end:?string,all_day:bool,location:string,response:string,cancelled:bool,organizer:string,join_url:?string,kind:string} */
    public function meetingInfo(string $providerMessageId): array;
    /** $action: accept | tentative | decline | remove (a cancelled meeting) */
    public function respondMeeting(string $eventId, string $action, string $comment = ''): void;

    // Automatic replies: ['status' => off|on|scheduled, 'start','end' (UTC 'Y-m-d H:i:s'),
    // 'internal','external' (HTML), 'audience' => none|contacts|all]
    public function getAutoReply(): array;
    public function setAutoReply(array $setting): void;
    public function createFolder(string $name, ?string $parentProviderFolderId): array;
    public function renameFolder(string $providerFolderId, string $name): void;
    /**
     * Delete a folder (Outlook: it and its mail go to Deleted Items; Gmail: the
     * label goes, the mail keeps its other labels).
     * @return string 'removed' (its mail left the mailbox view) | 'kept' (mail stays, without that folder)
     */
    public function deleteFolder(string $providerFolderId): string;

    // --- Change notifications -------------------------------------------
    public function createSubscription(string $providerFolderId, string $notificationUrl, string $clientState, string $expiresAtUtc): array;
    public function renewSubscription(string $subscriptionId, string $expiresAtUtc): array;
    public function deleteSubscription(string $subscriptionId): void;
    /** Longest subscription lifetime the provider allows, in seconds. */
    public function maxSubscriptionSeconds(): int;
}
