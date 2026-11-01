# Initial public preview verification

Verified 2026-11-01 in a separate local Docker project with a new MySQL database,
synthetic credentials and no live mail/AI/messaging tokens.

- The exported source built successfully with PHP 8.4 and locked Composer dependencies.
- Database migrations completed and the HTTP health check passed.
- First-admin setup, login, setup closure, mail/case pages and Community settings passed.
- Anonymous users were redirected to sign in before accessing settings.
- 32 Community feature checks, 48 retained legacy-signature/package compatibility checks,
  61 account/security checks, 50 mailbox-scope checks, 79 timeline checks and
  321 collaboration checks passed (591 assertions in those suites).
- The timeline fixture explicitly uses organization-shared mode; account-scope
  tests cover private-mailbox isolation separately.
- The export excludes private Git history, local settings/runtime data, operational
  notes and unreviewed setup screenshots. Known credential-pattern checks passed.
- Gitleaks passed after reviewing three false positives: a public Microsoft
  application ID and upstream minified viewer math/parser expressions. Narrow
  exceptions are documented in .gitleaks.toml. This is not a complete security audit.

Live provider OAuth, mail delivery/sync against real accounts, external AI, WhatsApp,
Telegram and Teams were not exercised by these synthetic release checks. Configure
and validate them with your own provider accounts before relying on them.
