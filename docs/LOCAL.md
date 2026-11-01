# Run Other Things on your computer

This standalone package starts a new MySQL database, creates all application tables, runs the PHP application, and starts the Outlook sync worker. It contains application code only: no existing accounts, emails, attachments, OAuth tokens, or server API keys are copied.

## Start

Install and start Docker Desktop with Linux containers, or Docker Engine with the Compose plugin on Linux. Extract the archive and open a terminal inside `other_things`.

Windows PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\Start.ps1
```

macOS / Linux:

```sh
sh start.sh
```

The first build downloads images and dependencies; keep the terminal open. The script prints a **first-admin setup code** and waits for startup health checks. Open:

<http://localhost:8080/other_things/web/setup.php>

Enter the setup code, your company name, name, email, and a new password (at least 10 characters). Setup closes after the first account is created. Sign in at:

<http://localhost:8080/other_things/web/>

The application and MySQL run in containers; you do not need PHP, Composer, or MySQL installed on your computer. Startup generates `.env` passwords and a persistent encryption key. Keep `.env` private.

## Connect Outlook

1. In your Entra app registration, add this **Web** redirect URI, preserving any existing redirect URI:
   `http://localhost:8080/other_things/web/oauth/callback.php`
2. Edit the generated `.env` in this extracted folder. Set `ECW_GRAPH_CLIENT_ID` to the application/client ID and `ECW_GRAPH_CLIENT_SECRET` to the secret **value**, not its ID. Use single quotes around secret values to preserve `$` characters, for example `ECW_GRAPH_CLIENT_SECRET='your-value'`.
3. Apply the environment changes:
   `docker compose up -d --wait --wait-timeout 240`
4. Sign in to Other Things, open **Settings → Mailbox**, and connect your Microsoft account. Leave Docker running while it synchronizes.

Each user connects their own Microsoft mailbox. The Microsoft app registration must support their account/tenant; some organizations require administrator consent. The existing delegated permission request remains unchanged. Initial synchronization can take time for large mailboxes. Local synchronization uses the background polling worker; no public webhook URL is required.

Microsoft documents localhost development redirects and the Web platform for PHP applications: [redirect URI configuration](https://learn.microsoft.com/en-us/entra/identity-platform/reply-url).

AI is optional. To enable the existing Claude assistant locally, set `ECW_CLAUDE_API_KEY` in `.env` and run the same Compose command. The server's existing key is deliberately not exported. Existing token/request limits remain in place.

## Connect Gmail (optional)

Gmail and Google Workspace mailboxes work next to Outlook ones; each user can connect either.

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project, then **APIs & Services → Library → Gmail API → Enable**.
2. **OAuth consent screen** (Google Auth Platform): choose **Internal** if everyone uses your Google Workspace domain (no Google review needed), otherwise **External**. Add the scopes `openid`, `email`, `profile` and `https://www.googleapis.com/auth/gmail.modify`.
   - External apps in **Testing** mode only work for the test users you list, and Google expires their refresh tokens after **7 days** (the mailbox then shows "Connection expired"). For lasting use, publish the app. Because `gmail.modify` is a *restricted* scope, an External app used beyond a few people needs Google verification and a yearly security assessment.
3. **Credentials → Create credentials → OAuth client ID → Web application**. Add the authorized redirect URI `http://localhost:8080/other_things/web/oauth/callback.php` (the same callback as Outlook).
4. Put the client ID and secret in `.env`:
   `ECW_GOOGLE_CLIENT_ID=...` and `ECW_GOOGLE_CLIENT_SECRET='...'`
5. Apply: `docker compose up -d --wait --wait-timeout 240`
6. **Settings → Mailbox → Connect Gmail.**

Gmail labels appear as folders; Inbox and Sent are synchronized. Archiving a message in Gmail keeps it in the app (it is still in All Mail); deleting or moving it to Trash/Spam removes it. New mail arrives by polling every ~15 seconds — Gmail push (Pub/Sub) is not used. Teams features remain Microsoft-only.

## Multiple users and private mailboxes

Under **Settings → Users**, create an invitation for a colleague's or contact's email. Copy the generated link and give it to that person. Links expire after three days and work once; the account is bound to the invited email. The person chooses their own password, signs in, and connects their mailbox. Administrators can also create accounts directly and disable access.

- Inbox, search, drafts, attachments, and mailbox settings are private to the connecting user, including against another workspace administrator using the application.
- Linking a conversation to a case shares that entire thread, its attachments, and future messages with **all members of that company workspace**, including invited contacts.
- Reading a shared case does not grant access to the rest of its mailbox or permission to send as its owner.
- Unlinking removes shared access to that thread. People may already have downloaded attachments.
- Cases have separate **Responsible** and **Handled by** selectors. Both select active members of the same company.

For local testing, use separate browser profiles or a private window to sign in as different users. `localhost` works only on your computer: invitations cannot give remote contacts access to this local installation. A shared HTTPS deployment is needed for people on other computers to use one workspace together. Separate local installations have separate databases.

## Stop, resume, and diagnose

```sh
docker compose stop
docker compose up -d --wait --wait-timeout 240
docker compose ps -a
docker compose logs --tail=100 init web sync db
```

`init` exits successfully after migrations; that is expected. `web`, `sync`, and `db` stay running. The first build needs internet; Microsoft synchronization, AI, and some existing viewer dependencies also use internet access.

If port 8080 is occupied, change `ECW_HTTP_PORT`, `ECW_BASE_URL`, and `ECW_GRAPH_REDIRECT_URI` consistently in `.env`, then add the corresponding redirect in Entra. Open the matching port in your browser.

Code is built into the image. After changing source, run `docker compose up --build -d --wait --wait-timeout 240`. Environment changes require container recreation with `up`, rather than just `restart`. Compose's startup/health wait behavior is documented [here](https://docs.docker.com/reference/cli/docker/compose/up/).

## Data and backups

Docker named volumes retain the database, attachments, cache, logs, and encryption key when containers stop or are recreated. Keep the database, storage volume, encryption-key volume, and `.env` together in backups; losing the encryption key prevents decrypting stored mailbox tokens. Do not delete the volumes to restart the application. Database and app ports are not exposed to your network; the web port binds to your computer's loopback interface.

The local flags are `ECW_LOCAL_FEATURES=1` and `ECW_PRIVATE_MAILBOXES=1`. The hosted installation keeps its previous defaults. Do not point this package at the hosted database.

## Public preview validation

See [PUBLIC-RELEASE.md](PUBLIC-RELEASE.md) for the fresh Docker and account/security checks performed on the public snapshot. Configure and test real provider accounts separately.
