# Eleon Community — Capabilities

Version 1.1.1 · updated 31 October 2026 (first written for 1.0.0, 7 October 2026) · Self-hosted (Docker) · PHP 8.4 · MySQL 8.4

Your Outlook or Gmail mailbox with cases on top. A case gathers everything about one matter, such as a complaint, a project or a warranty claim. That includes every conversation, internal note, step, deadline and file, across people and mailboxes. It runs on the customer's own server, so the mail stays in-house.

**Key figures**

- 2 mail providers: Microsoft 365 / Outlook and Gmail
- One open-source Community distribution with all installed functionality
- Optional features remain controlled by administrator and user settings
- 12 AI agents working in the background
- Historical database-query benchmark with 110,000 emails; not an end-to-end page-load guarantee (see section 9)
- Automated checks of the server, the screens and the browser, all passing on 31 October 2026, also on an empty database

Community includes the functionality formerly grouped as Free, Pro/Team and Business. No paid key or trial is required. External providers and infrastructure may charge separately.

---

## 1. Mail

A complete mail client that keeps Outlook or Gmail in sync both ways. Nothing in the mailbox changes until someone acts.

**Reading and organising**
- Every folder from Outlook or Gmail, the person's own folders included, plus Archive, Junk and Deleted Items. Outlook's bookkeeping folders are hidden.
- New mail arrives within seconds (Microsoft push notifications).
- Read, flag and category changes go back to Outlook.
- Select several emails, right-click menus, drag and drop, keyboard shortcuts.
- Snooze until a time, with an optional matching move in Outlook.
- Reminders that stay quiet if the customer replies first.
- Personal colour rules for the list. 
- Built-in viewers for PDF, Word, Excel, PowerPoint and DXF drawings.
- Print, pop out, and find words inside a conversation.

**Writing**
- Rich text editor, several signatures, attachments, Cc and Bcc.
- Undo send for a few seconds; send later at a chosen time.
- Drafts save as you type and appear in Outlook's Drafts, and the other way round.
- Reply templates.
- Writing a draft together: a colleague opens and edits the same reply, one at a time. 
- Out-of-office replies set from the app. 
- A banner shows when a colleague is viewing the same conversation.

**Search**
- Finds words in the text, subjects, people, and inside attached PDF and Office files.
- Works in any language, Greek included.
- Filters: `from:` `to:` `cc:` `subject:` `filename:` `has:attachment` `is:unread` `is:flagged` `is:linked` `is:unlinked` `case:` `folder:` `after:` `before:` `"exact phrase"`
- A filter button builds the search for people who don't type syntax.

**Shared mailboxes**
- Team inboxes such as info@ and sales@, connected from Microsoft 365 or marked as shared.
- Claim a conversation so the team sees who is on it; replying claims it too.
- Mine, Unassigned and Others' views above the list.
- Private by default: colleagues never see a person's own mailbox, only conversations put into a case they can see.

## 2. Cases

The core of the product. A case can hold many conversations from different mailboxes, and everyone who can see the case reads them in one timeline.

**Starting and filling a case**
- From an email (Add to case, with suggested cases that share the same people), or with New case for a call, a visit or a task.
- Case numbers (CASE-00012), status Open / Waiting for the customer / Closed.
- One timeline of every email of every conversation, in order; replies that come later join by themselves.
- Start a new conversation from inside the case.
- Case PDF export and print. 

**People, dates and steps**
- Responsible (answers for the case) and Handled by (does the work); both are told.
- Checklists: steps with a person and a date, a reminder on the day, and a "3/5" progress count in the list.
- Case templates: New customer, Complaint, Warranty claim and Support request to start from, each with its steps, tags, due date and group. 
- Due dates with reminders, tags, and merging two cases. 
- Groups (Sales, Support…) with automatic assignment. 
- Reply clock counted in business hours, with a target per group. 

**Notes, sharing and history**
- Internal notes on a single email or on the whole case; customers never see them.
- @mentions bring a colleague in, and add them to the case if they couldn't see it.
- Note threads that can be marked resolved.
- Visible to everyone by default, or limited to chosen people and a group.
- A history of who did what on the case.
- Teams chats shown inside the case. 
- Legal hold: the case can't be deleted and its emails stay. 

**The case list**
- Quick views: All, Open, My cases, Unassigned, Overdue, Due this week, Waiting for the customer, each with a count.
- Search by name, number or subject; filters for a person, dates, attachments and status; sorting.
- Tick several cases to close, reopen, assign, date or group them together.
- A ⋮ menu on each row changes a case without opening it.
- People pickers with photos; "You" and relative times for quick reading.

## 3. Working as a team

**People and roles**
- Three roles. Admin manages users, settings and security. User does the daily work. Read only can look but change nothing (for a manager, an auditor or a trainee).
- Invitations by email; people can be switched off without losing their work.
- Photos from Outlook throughout the app.

**Absence and leaving**
- Holiday cover: dates and a colleague who sees the person's cases and gets copies of their alerts while they are away. It ends by itself.
- Handover when someone leaves: open cases, reminders and claimed conversations move to a colleague in one step.

**Staying informed**
- Notification bell in the app, push notifications on the phone, and email.
- Installable phone app (home-screen app) with mobile layouts.
- Case events posted to a Teams channel. 

## 4. AI assistant and agents 

The AI only sees what the person asking can see, shows the emails it used, and never sends anything by itself.

**Assistant**
- Ask AI about mail and cases: "what did we agree with Maria about delivery?", "find the invoice from Kostas", "summarize this case".
- Draft a reply in the customer's language; [brackets] mark what only the person knows (a price, a date).
- AI case summary.
- Choice of model: the built-in Claude service, or any OpenAI-compatible endpoint the company runs (Ollama, LiteLLM, a company gateway), with daily limits.

**Microsoft 365 Copilot and other AI apps**
- A connection (MCP) for Microsoft 365 Copilot, signed in with the company's Microsoft accounts. The admin downloads the agent package from Settings.
- Personal access tokens for other AI apps such as Claude or VS Code.
- Read-only: the assistant's tools that look things up (search, count and read emails, read and list files, find amounts, cases, unanswered emails), limited to what that person can see. Proposing a case, a note or a reply stays in the app, where a person confirms it.

**The background agents**

They read new mail and leave suggestion cards with one-click actions: start a case, save the facts, set a reminder, draft a reply. They never read Junk or Deleted Items. Admins switch each agent on or off and see what it did this week.

| Agent | What it does |
|---|---|
| Triage new email | Reads each new email: what it is (quote request, order, complaint, invoice…), how urgent, any reply-by date, key numbers, and which case it belongs to. |
| Promises and deadlines | Spots promises in sent emails ("we will send it by Friday") and offers a reminder for that day. |
| Living case summaries | Keeps a short summary of every active case up to date: status, what is open, who waits for whom, next step. |
| Next step and references | Fills in each active case's next step, who it waits on, when to look again, and its reference numbers (order, ticket, serial, claim). Marked as AI; one click undoes it. |
| No reply yet | A question got no answer for some working days: offers to follow up. |
| Left unanswered | An email sent to the person (not a copy) that asks something has waited a working day without an answer: tells them. |
| Reply-time alerts | Tells Responsible before a case misses its reply target. |
| Morning briefing | On working mornings: customers waiting, overdue and due cases, open suggestions. |
| Patterns | Three or more complaints from one company in a month: suggests a quality case. |
| Solutions memory | When a case closes, writes down the problem and how it was solved; when a new case looks like an earlier one, shows it and its solution (Yes / No). |
| Deals | For cases that look like a sale or a purchase: the RFQ, offers and revisions with totals, the PO, confirmation and delivery (Deals tab). |
| Prepared replies | Writes a draft reply for emails that need one, ready to check and send. Off by default; uses more AI. |

## 5. Calendar, contacts, Teams, reports

- **Calendar :** the person's Outlook calendar: view, create, move and edit events.
- **Contacts :** companies and people the office has mail with, with all their cases and conversations on one page and details colleagues add; contact cards linked from mail.
- **Microsoft Teams :** a Teams tab, a launcher and chat windows alongside mail; Teams buttons on conversations and cases.
- **Reports :** cases by status, created and closed, trend by week or month; workload by group, Responsible and Handled by; first reply time, time to close, replies within 24 hours; business-hour reply targets (met, late now, nearly late); busiest customers; cases with no activity for 7+ days; CSV export.

## 6. Automation and integrations

- Automation rules : admin rules that act on new mail — open or file cases, assign, tag, move, notify, reply automatically.
- Outgoing webhooks to other systems when things happen in a case.
- Teams channel posts. 
- Microsoft 365 Copilot and AI apps (see section 4).

## 7. Security and compliance

The mail stays on the customer's server. Microsoft or Google sign-in tokens are stored encrypted.

- Passwords with two-factor sign-in (authenticator app), for everyone.
- Sign in with Microsoft, which can be made required. 
- Limits on repeated sign-in attempts and requests.
- Retention: how long deleted cases, old email copies and the audit log are kept. 
- Legal hold for disputes and claims. 
- Audit log of sign-ins, user and settings changes, deletions, exports, downloads and AI questions, filterable and exportable. 
- Backups encrypted with a backup password. 
- Optional encryption of the database files on disk, with the key kept separately.

## 8. Backups, health, updates

For an office without an IT department: the app looks after itself and says when it needs a person.

- A backup every day, kept on the server; off-site copy to a NAS or OneDrive folder, S3 storage or WebDAV; restore with one script.
- System health page: mail sync, background worker, every mailbox, sync queue, disk space, database memory, backups, off-site copy, license, version.
- When something stays broken for 10 minutes, admins are told in the app and by email, and again when it is fixed.
- One-command updates. Each release is checked as signed by the vendor, a backup is made first, and the previous version comes back by itself if the update fails.
- License keys signed by the vendor; a 30-day Business trial on a fresh install, then Core.

## 9. Speed at large volumes

Measured for version 1.0.0 (7 October 2026) on a test copy with 110,000 emails, 37,500 conversations and 4,000 cases (one mailbox with 60,700 emails). Times are the database work behind each screen. Not measured again since; treat them as that release's figures.

| Screen | Before tuning | Now |
|---|---|---|
| Folder list with unread counts | 2,776 ms | 1 ms |
| Inbox, first page | 1,292 ms | 55 ms |
| Search, one word | 789 ms | 42 ms |
| Search, two words | 1,180 ms | 46 ms |
| Search, a Greek word | did not finish | 29 ms |
| Search by sender, unread or file name | 400–1,000 ms | 13–24 ms |
| Case list, filtered by a person | 265 ms | 15 ms |
| Reports, last 30 days | 841 ms | 274 ms |

All 32 measured screens are under the 300 ms target. On a large installation, give the database more memory (one setting); System health warns when it is too small.

## 10. Help and first day

- Getting started: a setup checklist that ticks itself off (connect the mailbox, signature, two-factor sign-in; for admins: company name and time zone, invite the team, case templates, safe backups).
- Guided tours of Mail, Cases and a case: the screen dims except one element at a time, with a short caption.
- 29 answers, each with a one-line version first; "Show me" points at the button to press.
- The ? key opens the help on any page; first-time visitors are offered the tour once.
- Each person picks how much they see: Simple, Standard, Everything or Custom. Appearance themes, including dark.

## 11. Packages

Each package includes everything in the one before it.

| | Core | Team | Business |
|---|---|---|---|
| Users | 3 | No limit | No limit |
| Mailboxes | 2 | No limit | No limit |

**In every package:** Outlook and Gmail with two-way sync · cases, timeline, notes, follow-ups and sharing · checklists · reply templates · shared mailboxes and claiming · holiday cover and handover · drafts, undo send, send later, signatures · file viewers · phone app and push notifications · two-factor sign-in · daily and off-site backups · system health and alerts · signed updates · guided help.

| Feature | Core | Team | Business |
|---|---|---|---|
| Calendar | — | ✓ | ✓ |
| Contacts and companies | — | ✓ | ✓ |
| Reports | — | ✓ | ✓ |
| Case templates | — | ✓ | ✓ |
| Case groups with auto-assignment | — | ✓ | ✓ |
| Tags, due dates and merging cases | — | ✓ | ✓ |
| Response-time targets | — | ✓ | ✓ |
| Case PDF export | — | ✓ | ✓ |
| Automation rules | — | ✓ | ✓ |
| Mail colour rules | — | ✓ | ✓ |
| Writing drafts together | — | ✓ | ✓ |
| Out-of-office replies | — | ✓ | ✓ |
| AI assistant and background agents | — | — | ✓ |
| Microsoft Teams | — | — | ✓ |
| Sign in with Microsoft | — | — | ✓ |
| Legal hold and retention | — | — | ✓ |
| Audit log | — | — | ✓ |
| Backup password encryption | — | — | ✓ |

## 12. Who sees what

A feature appears only when three layers allow it. The server checks every page and action, so hiding a button is never the only protection.

1. **Package:** what the company bought (Core, Team or Business).
2. **Feature access:** what the admin lets members use, one switch per feature. Admins always pass this layer.
3. **My view:** what each person wants on screen (Simple, Standard, Everything or Custom). It can only hide, never unlock.

Mail, cases, notes, follow-ups and sharing are the core product and can't be switched off. Help follows the same rules: a person never sees answers or tour steps for features they don't have.

## 13. Built since 1.0.0

These were listed here as "not built yet" and now exist:

- **Error reporting:** server and browser errors collected in one place, grouped by kind, shown in System health (lib/errors.php).
- **Full data export:** one case, or the whole workspace, as a ZIP that opens without the app: emails as .eml, the files, a readable page, CSV and JSON (lib/export.php).
- **Agents that learn:** the Solutions memory agent, and case-group routing that learns from what people accept, change or undo (lib/case_memory.php, lib/group_rules.php).
- **Greek interface:** each person picks English or Ελληνικά in Settings → Account (lib/i18n.php). How much of every screen is translated has not been fully checked yet.
- **Case settings:** own statuses, roles, case numbers, due rules and tag colours; saved filters on the Cases list.
- **Chats in cases:** Teams, Telegram and WhatsApp conversations shown in the case timeline (modules).
- **A better bell:** the workspace one is in (other workspaces counted), a red badge and an Important tab for mentions, assignments, reminders, due dates and failures, repeats folded, links removed for cases one can no longer open, and quiet hours (no push or pop-ups) in Settings → Notifications.
- **Large attachments:** up to 25 MB of files per email, with an upload bar. Outlook gets large files in pieces (an upload session), Gmail through its upload address.

## 14. Not built yet

- **Next — Board view for cases:** cases as cards in columns by status or group, moved by dragging.
- **Later — Business extras:** court-ready case export, document versions and transmittals, a client portal, and AI that runs entirely on the server. A company's own model endpoint can already be used today.

The full list, in order, is in docs/PROJECT-REVIEW-2026-10-31.md.
