# Microsoft 365: approve the app once for the whole company

Without this, every colleague sees Microsoft's permission screen when they connect their
mailbox (email, calendar and automatic replies together), and once more the first time they
open Teams chats. With it, a Microsoft 365 administrator approves everything once, and
nobody in the company is asked again.

What is approved (all "delegated": the app acts only as the signed-in person, and sees only
what that person can see in Outlook):

| Permission | What for |
|---|---|
| Read and write mail (Mail.ReadWrite) | Read, move, flag, mark read, folders |
| Send mail (Mail.Send) | Send replies and new emails |
| Mail of shared mailboxes (Mail.ReadWrite.Shared, Mail.Send.Shared) | info@, sales@… mailboxes the person has access to |
| Calendars (Calendars.ReadWrite) | The Calendar tab, meetings |
| Mailbox settings (MailboxSettings.ReadWrite) | Automatic replies (out of office), time zone |
| Chats (Chat.ReadWrite) | Teams chats inside the app |
| Profile, stay signed in (User.Read, offline_access) | Who is connected; no new sign-in every hour |

Who can do it: a **Global Administrator**, **Application Administrator** or **Cloud
Application Administrator** of your Microsoft 365.

## The quick way: from the app

1. Sign in to the app with the account that has the company's mailbox connected.
2. Go to **Settings → Mailbox**, to that mailbox (with several accounts: the one of that company).
3. Open **Approve for the whole company** (below Blocked senders) and press its button.
   It opens Microsoft's approval for that mailbox's own company. Personal accounts (Hotmail,
   Outlook.com) do not show it: they have no administrator.
4. Microsoft asks you to sign in: use the **Microsoft 365 administrator** account.
5. Microsoft lists the permissions above and shows the box "Consent on behalf of your
   organization". Press **Accept**.
6. You come back to the app. Done: colleagues can now connect without any permission screen.

## The other way: from the Microsoft admin portal

1. Open <https://entra.microsoft.com> and sign in as the administrator.
2. Go to **Identity → Applications → Enterprise applications**.
3. Find the app (the name it has in your app registration, for example "Email Case Workspace").
   - If it is not in the list, nobody has signed in to it yet: use the quick way above once instead.
4. Open it, then **Security → Permissions**.
5. Press **Grant admin consent for <your company>**, sign in again if asked, and press **Accept**.
6. The list now shows every permission with "Granted for <your company>".

## Good to know

- **Personal accounts** (Hotmail, Outlook.com) have no administrator: they still see one
  permission screen when connecting. Teams chats do not exist for them.
- **New permissions later**: if a new version of the app needs a permission it did not ask
  for before, colleagues may see a screen again, or you approve once more the same way.
- **Taking it back**: Entra → Enterprise applications → the app → **Delete**, or
  **Properties → Enabled for users to sign in: No**. Mailboxes then stop syncing until approved again.
- **If a colleague still sees "Need admin approval"**: your company blocks people from
  approving apps themselves. The admin approval above fixes it for everyone.
