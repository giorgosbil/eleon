# Security reports

The initial public application release is a preview. Upgrade guidance will accompany
future releases; no long-term support period is currently promised.

Report suspected vulnerabilities privately to **georgebilalis@hotmail.com**, with
the subject "Eleon security report". Include the version/commit, impact and steps
using synthetic data. Do not publish exploit details, credentials or mailbox data
in an issue. We will review the report and coordinate next steps.

For deployment: use HTTPS, protect .env and encryption keys, restrict the web root
to web/, configure provider permissions and keep backups private. Feature access
and sign-in permissions remain enforced in Community; open source does not make
mailbox contents publicly accessible.
