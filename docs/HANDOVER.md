# Handover: continuing work on another PC

For a new Claude Code conversation picking up this project. Read this first,
then CHANGELOG.md (top section "Unreleased (1.1.1)") for what changed lately.
Last updated 2026-10-12.

## The project

Email Case Workspace ("Case Workspace"): a self-hosted web app that syncs
Outlook / Gmail mailboxes and groups conversations into **cases**. PHP 8 +
MySQL 8.4 in Docker, plain JS front end (no framework). Owner: George Bilalis
(Zetisis / InQuality). He is not a developer: explain in plain words, short,
actions first. For design questions he likes to **talk first** ("lets talk
first"), then build.

- Code: `C:\xampp\htdocs\other_things`, GitHub `giorgosbil/case-workspace`.
- App: http://localhost:8080/other_things/web/
- Main parts: `lib/` (logic), `web/` (pages, `web/api/index.php` = all API
  actions, `web/assets/*.js|css`), `bin/` (worker, sync daemon, CLI tools),
  `database/migrations/` (numbered SQL, applied by the `init` container),
  `tests/` (plain PHP test files).
- Mailboxes now: `hello@example.com` (id 1) and `georgeb@inquality.gr`
  (id 2138, connected 2026-10-10, older mail still importing in the background).

## Running and testing

- Rebuild after ANY code change (code is copied into the image):
  `docker compose up --build -d --wait --wait-timeout 600`
- Full tests (all must pass before saying done):
  `docker compose run --rm --no-deps -T -v "<repo>/tests:/opt/ecw/tests" web sh tests/run_all.sh`
  In Git Bash use `MSYS_NO_PATHCONV=1` and `$(pwd -W)` for the repo path.
- Tests run in transactions on the live database and fake Microsoft with
  `tests/support/FakeGraph.php`. Never let a test reach the real mailbox.
- Quick DB look: `docker compose exec -T web php -r 'require "/opt/ecw/lib/db.php"; ...'`
  (one-off commands no longer email "new problem" alerts, see below).
- Screenshots: headless Edge via a small node script with a session cookie made
  in the container; delete those sessions (`storage/sessions/sess_ftshot*`) after.

## Rules to keep (learned the hard way)

1. **Never send email, create drafts or change anything in the real mailboxes
   while testing.** Opening the composer autosaves a draft after ~1 s, so do not
   open it on the live app to test.
2. **Never print, move or commit secrets**: `.env`, and the license signing key
   (`C:\Users\georg\.ecw-license\license-private.key`, only on the FIRST PC; it may
   only be mounted read-only into a one-off container to sign a release).
3. **Keep LF line endings.** Edit with the Edit tool or Python `read_bytes /
   write_bytes`; never PowerShell `Get-Content/Set-Content` on project files
   (corrupts UTF-8 / CRLF). `.gitattributes` keeps `* -text`.
4. Python inside bash heredocs mangles `\n`, `\f`, `\'`: for strings with
   backslashes use the Edit tool or a script file. (A `\f` once became a
   form-feed byte and silently broke the rest of collab.css.)
5. **Migrations**: the runner splits on `;`, so no `;` inside comments or
   strings. New tables must also be added to `ECW_T` in `lib/schema.php`.
6. Don't change the owner's settings (AI agents, alerts, rules) without asking.
7. Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`;
   the owner normally commits himself with `Push.cmd`.

8. Before a DataPush on any PC, run `Pull.cmd` first: until 2026-10-16 backups
   dropped every "DEFAULT CURRENT_TIMESTAMP" column (created_at, case history
   times…); fixed in lib/backup.php (tested in security_test.php).
9. The screenshot script splits its argument on `||`: never use `||` in the
   JavaScript passed to it (use `??` or a ternary).
10. AI is paused for the workspace (Settings → AI): Deals are read by rules only
   and the triage / drafter agents do not run until it is switched back on.

## Working on two PCs

- Code travels through GitHub: `Pull.cmd` at the start of work, `Push.cmd` at
  the end.
- Data travels separately, encrypted, through a folder both PCs see (OneDrive
  by default), never through GitHub (`scripts/data-sync.ps1`):
  - Stopping on a PC: `Push.cmd`, then `DataPush.cmd` (copies the data, stops
    the app there).
  - Starting on the other: `Pull.cmd`, then `DataPull.cmd` (REPLACES that PC's
    data; what it had is saved first as `backups\ecw-backup-…-prerestore.zip`).
  - Each run shows the data folder (`ECW_DATA_SYNC_DIR`): Enter keeps it, or
    paste a new location ("Desktop", "Documents", "OneDrive" mean their
    `CaseWorkspace-Data` folder; never a folder inside the app). The first run
    also asks for the data password (`ECW_BACKUP_PASSWORD`, the same on both
    PCs). Both are kept in `.env`. Without `latest.json`, Pull takes the newest
    `ecw-data-*.zip` in the folder.
  - Guards: Push warns when the other PC sent data this one never pulled; Pull
    refuses data from newer code (run `Pull.cmd` first) and waits for OneDrive
    to finish downloading. The newest three data files stay in the folder.
  - What travels: the whole database (mailboxes and their connection, emails,
    cases, notes, deals, settings, users), drafts' files, logo, profile photos,
    the encryption key. Attachment files and logins do not (they come back by
    themselves: downloaded again, log in again).
- Only one PC may run the app at a time (`docker compose stop` on the other;
  `DataPush` does it), otherwise both sync and run agents/rules twice.
- Releases (`tools\release\Release.ps1`) only on the first PC (signing key).

## Recent work (2026-10-10 … 12), all tested, see CHANGELOG

- **One account at a time** (`lib/account.php`): picker at the top; Mail,
  Cases, Contacts, Calendar, Teams, Reports, Suggestions, badges, drafts,
  signatures (default per account), own templates and colour rules follow it.
  `cases.mailbox_id` + "case with a conversation in the account" decides cases.
- **History in two steps**: last 2 years of every folder first, then older
  mail in the background (`ecw_history_phase`, `recent_done_at`).
- **Disk**: attachment files read for search are not kept; cache cleared after
  7 days (`lib/storage.php`); System health shows space used.
- **Rules**: "Folder is" condition; **Run on earlier emails** with dates,
  preview and background progress (`lib/rule_backfill.php`). Old mail never
  gets auto-replies, notifications or webhooks.
- **Mail**: list keeps its position on refresh/delete/case-link and after a
  reload; Outlook-style date groups; minimized replies reopen with their
  conversation; Drafts list fixes (forgotten Outlook drafts kept in DB).
- **Choices instead of risky buttons** (`confirm-button.js`: `ecwChoice`,
  `ecwArm`): × on the composer → "Save to Drafts" / "Delete".
- **Column menus** on tables (`table-tools.js`); Cases sorts on the server.
- **Incidents fixed**: (a) one-off server commands and tests were reported as
  app problems and emailed to the admin, now only the website and `bin/`
  services report (`ecw_errors_ignored`); (b) unsent drafts deleted by the app
  landed in Outlook's Deleted Items, were synced back and looked like sent
  replies: drafts are never stored as emails now, app-deleted drafts are erased
  (`permanentDelete`), AI-prepared drafts stay in the app (`keep_local`).
  Nothing was ever sent to customers.

## Open, discussed but not built

1. **Add to case / Create case dialog** in Mail: prefill the name from the
   subject, group / Responsible / due date, customer, detected reference
   numbers as chips, better "suggested case" with reasons, richer rows,
   keyboard, Undo / Open case. The owner thinks it is incomplete; build first.
2. **AI agents on imported mail**: make sure agents act on genuinely new mail
   only (on 2026-10-10 the drafter wrote 24 drafts during georgeb's import).
3. Notifications (bell) per account; choice of which mailbox sends admin alerts.
4. "Run on existing emails" was offered for automation rules (done as above).
5. Signed release 1.1.1 and a test install at the company.
