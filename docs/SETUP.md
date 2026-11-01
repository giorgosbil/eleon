# Operator setup — Microsoft 365

For the one-time product-owned multitenant Entra application and server credentials, follow [Microsoft product bootstrap](../scripts/setup_microsoft_app.md). Normal users only use Settings → Mailbox → Connect Microsoft Outlook.

## 3. Apply the schema

```bash
cd /path/to/eleon
php bin/migrate.php --status     # what is applied
php bin/migrate.php              # apply what is pending
```

---

## 4. Create the first admin

Nobody can sign in yet, so the first account is created on the command line.
After this, users are added in the browser under **Settings → Users**.

```bash
cd /path/to/eleon
php bin/dev_user.php --org="Zetisis" --name="Georgios" \
                     --email=you@example.com --password='choose-a-long-one'
```

The password must be at least 10 characters. The account is created as an
organization **admin**, which is what lets it add everyone else.

Then open the application and sign in:

```text
https://example.com/other_things/web/
```

---

## 5. Connect the mailbox

Sign in, then go to **Settings → Mailbox → Connect Microsoft Outlook**. After
consent you land back on Settings → Mailbox, which creates the mailbox row and
queues folder discovery.

Then drain the queue once, by hand, to watch it work:

```bash
php bin/sync_worker.php
php bin/mailboxctl.php --list
php bin/mailboxctl.php --status=1
```

Folder discovery selects **Inbox and Sent** for sync and nothing else. Select
more with SQL for now (`is_selected_for_sync = 1` on `ecw_mail_folders`) — the
Settings UI belongs to a later step.

Optional, to enable the drag-a-mail-here workflow:

```bash
php bin/mailboxctl.php --intake=1 --name="Create Case"
```

---

## 6. Keep it running

Mail synchronizes continuously through a systemd service. **This is not
optional** — the Sync button only ENQUEUES work, so with nothing draining the
queue, mail never arrives at all.

```bash
systemctl status  ecw-sync     # is it running?
systemctl restart ecw-sync     # after changing anything under lib/sync/
journalctl -u ecw-sync -n 50   # service-level problems
tail -f other_things/logs/sync_daemon.log   # what it is actually doing
```

The unit is `/etc/systemd/system/ecw-sync.service` and runs
`bin/sync_daemon.php` as `www-data`. It asks Microsoft what changed every 15
seconds, drains the job queue continuously, warms message bodies every 2
minutes, and renews subscriptions hourly. It exits once an hour on purpose so
systemd restarts it, because a PHP process that lives for weeks eventually
becomes the thing nobody wants to debug.

The browser polls the local database every 8 seconds
(`api/index.php?action=updates`), so new mail appears without anyone pressing
anything. Worst case from "Microsoft has it" to "it is on screen" is about 25
seconds.

`bin/sync_cron.php` and `bin/sync_worker.php` remain for one-off manual runs and
would work from cron — but cron's one-minute floor is the reason the service
exists instead.

---

## 7. Near-real-time updates (optional, and public-HTTPS only)

Graph will only deliver notifications to a **publicly reachable HTTPS URL** with
a valid certificate, and it validates the endpoint at subscription time by
calling it and expecting the `validationToken` echoed within seconds. A host
Microsoft cannot reach simply fails to create the subscription.

```bash
php bin/mailboxctl.php --subscribe=1 \
    --notification-url=https://example.com/other_things/web/webhook.php
```

Everything works without this. You lose seconds-level latency, not mail.

---

## 8. Verifying

```bash
php tests/schema_test.php     # step 01 — schema conformance
php tests/sync_test.php       # step 02 — sync, scripted Graph, no network
php tests/mail_test.php       # step 03 — search, composer, sanitization
php tests/timeline_test.php   # step 04 — the case timeline
php tests/render_test.php     # steps 03-04 — the pages and API actually render
php tests/wiring_test.php     # every entry point can call what it calls
php bin/mailboxctl.php --status=1
```

The application entry point is `/other_things/web/` — it sends you to the login
page, and to the mail interface once you are signed in.

`--status` prints the mailbox state, per-folder sync times, and subscription
health including how long each subscription has been silent. A subscription
that is `active` but has never delivered a notification is the failure mode
notifications actually have.
