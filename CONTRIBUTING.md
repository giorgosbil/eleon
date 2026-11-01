# Contributing to Eleon

Eleon is open-source email, case management, collaboration and optional AI software.
Fork this repository, create a focused branch and open a pull request explaining
the user-visible problem, your change and its validation. Use AGPL-3.0-compatible
contributions and retain third-party attribution.

Start with the Docker setup in README.md. Run `php tests/community_edition_test.php`
for Community feature availability; database tests require a separate disposable
database, never a production mailbox/database. The initial release verification
is documented in docs/PUBLIC-RELEASE.md.

Use synthetic examples. Never commit .env, credentials, OAuth tokens, email contents,
customer records, database dumps or backups. Report security issues privately using
SECURITY.md. Community participation does not include a guaranteed support response.
