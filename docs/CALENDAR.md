# Calendar

Open **Calendar** beside Mail in the top navigation. It shares the application's
appearance settings and uses the signed-in user's first connected Outlook account
in the current organization. Events are read live from Microsoft, without a new
database migration.

Choose Month, Week, Day or Agenda. Use Today, the arrow buttons or the small date
picker to navigate. Search filters events in the visible period. Click an event
for its details, or use New event. Double-click empty space in a month cell or
time column to create an event there. Week and day views place overlapping
appointments beside each other. Times use the browser's local timezone.

Create, edit and delete personal appointments here. Meetings with attendees and
recurring events open in Outlook for managing invitations and series. All-day
events use an inclusive Last day field; the server converts this to Microsoft's
exclusive end date.

If prompted, click **Enable Outlook calendar** and sign into Microsoft. The app
registration needs the delegated Microsoft Graph `Calendars.ReadWrite`
permission; the organization may require an administrator to approve it. Calendar
authorization is opt-in. Existing mail authorization and refresh scopes stay the
same, and calendar access tokens are cached encrypted in the current session.

Keyboard shortcuts outside controls: T for Today, N for New event, and left/right
arrows for the previous/next period.

Validation: `php tests/calendar_test.php` covers account/organization scoping,
timezone and date validation, pagination, idempotent creation, and mutation guards.
