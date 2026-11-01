# Install Eleon Community

Eleon is a self-hosted application for Outlook/Gmail email, cases, collaboration
and optional AI. All installed software features are included without a paid key.

## Start from source

Install Git and Docker Desktop with Linux containers (or Docker Engine + Compose
on Linux). Clone https://github.com/giorgosbil/eleon and enter its folder.

- Windows: `powershell -ExecutionPolicy Bypass -File .\Start.ps1`
- Linux/macOS: `sh start.sh`

The first build needs internet. Use the first-admin setup code printed by startup
at http://localhost:8080/other_things/web/setup.php, create an account, then connect
your provider in Settings. `welcome.html` is the text configuration guide.

## Provider configuration

You supply your own Microsoft/Google app registration and provider permissions.
Keep client secrets in .env, never in Git or an issue. Consult [local setup](docs/LOCAL.md)
and [Microsoft app registration](scripts/setup_microsoft_app.md). AI and messaging
connections are optional and need their own configuration and provider accounts.
Provider/API usage and infrastructure may cost money independently of Eleon.

## Operation

Keep .env, the encryption key, database and backups together and private. Bind to
loopback for local use; configure HTTPS and deliberate network access for a server.
Resource requirements depend on mailbox volume, attachments and enabled services.
Test backup/restore before relying on an installation.

This initial public preview has fresh-install and synthetic account/security checks;
see [release verification](docs/PUBLIC-RELEASE.md). It is not a production-readiness
certification or a guarantee about external provider behavior.
