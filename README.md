<p align="center"><img src="assets/eleon-readme.svg" width="64" height="64" alt="Eleon logo"></p>

# Eleon

**Open-source email, cases, collaboration and AI.**

Eleon is a self-hosted email workspace for Outlook and Gmail. Read and send mail,
create cases from related conversations, collaborate with your team, and use
optional AI to understand the work and prepare your next action.

[Product and interactive demo](https://eleon.app/) ·
[Capabilities](https://eleon.app/features/) ·
[Self-hosting](https://eleon.app/self-host/) ·
[Deployment and support](mailto:georgebilalis@hotmail.com?subject=Eleon%20services)

## Why Eleon?

A customer, supplier and colleague can discuss the same matter in separate threads.
Eleon puts those conversations on one chronological case timeline, with an owner,
status, internal notes, checklist and next step. Microsoft and Google continue
delivering your email; Eleon connects to your existing accounts.

## Included in the open-source application

- Outlook/Gmail email, folders, search, drafts, replies and supported two-way sync.
- Case creation, linked conversations, internal discussion, checklists and next steps.
- Shared mailboxes, assignments, contacts, templates and supported Outlook calendar.
- Optional AI summaries, reply drafts, translation, tool-assisted actions and background routines.
- Automation, response-time targets, reports and shared drafts.
- Installed Teams, WhatsApp, Telegram and case-chat integrations.
- Account security, audit, retention, legal hold, exports and backup capabilities.

**All installed functionality is available without a paid software license or trial.**
Administrators still control roles, feature access and user views. Connections need
provider configuration. AI is optional: choose your provider or compatible local
endpoint. Provider subscriptions, API/model usage and hosting may have their own costs.

## Status

This is the first public **preview**, not a production-readiness certification.
The full current application snapshot is published under AGPL-3.0-only. The former
Free/Pro/Business feature split no longer limits the Community build.

A fresh Docker build, database migrations, first-account setup, login and account
access checks were verified in isolation. See [release verification](docs/PUBLIC-RELEASE.md).
Live Outlook/Gmail OAuth, external AI and messaging providers require your own
credentials; those external integrations were not exercised in that release check.

## Quick start

Install Git and Docker Desktop (Linux containers), or Docker Engine with Compose.
Clone the repository and enter its folder:

```sh
git clone https://github.com/giorgosbil/eleon.git
cd eleon
```

On Windows PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\Start.ps1
```

On Linux/macOS:

```sh
sh start.sh
```

The startup script generates local passwords and an encryption key, builds the
application and initializes MySQL. Use the first-admin setup code printed by the
script at <http://localhost:8080/other_things/web/setup.php>. Create your account,
then connect your mail provider. Open `welcome.html` for the configuration guide.
The internal `/other_things/web/` path is retained for installation compatibility.

Keep `.env`, the encryption key, database and backups private. Back up the key with
your data. Read [local setup](docs/LOCAL.md) and [INSTALL.md](INSTALL.md) for provider
registration, configuration, ports, HTTPS and operational requirements.

## Hosting and services

Self-hosting the software is free. Paid work can cover deployment, migration,
training and support. Managed hosting is planned; no hosted subscription is sold
from this repository. [Contact George](mailto:georgebilalis@hotmail.com) to discuss a setup.

## Contribute

Read [CONTRIBUTING.md](CONTRIBUTING.md), propose focused changes and use synthetic
data in issues and tests. Report vulnerabilities privately using [SECURITY.md](SECURITY.md).

## License

Eleon original application code: [AGPL-3.0-only](LICENSE).
Copyright © 2026 George Bilalis. Third-party components retain their own licenses;
see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
