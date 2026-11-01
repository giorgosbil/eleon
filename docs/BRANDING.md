# Product branding on each installation

The application includes both icon sets in `web/assets/brands/original/` and
`web/assets/brands/eleon/`. Original is the penguin and **Case Workspace**;
Eleon uses the Eleon mark and name. The old `web/assets/icons/` files remain
unchanged for existing shortcuts.

On Windows, run `Branding.cmd` and choose Original or Eleon. Or use:

```powershell
.\scripts\branding.ps1 -Brand original
.\scripts\branding.ps1 -Brand eleon
```

The script changes only `ECW_PRODUCT_BRAND` in this computer's ignored `.env`
and recreates the application containers to apply it. Run a normal code update first if
the running image predates branding profiles. No prompt is needed on every pull.
The other computer keeps its own choice. DataPush/DataPull does not transfer
`.env`, so restoring the workspace does not change the product profile either.

For Linux or a manually configured deployment, set `ECW_PRODUCT_BRAND=original`
or `ECW_PRODUCT_BRAND=eleon` in the server environment, then restart PHP/the web
container. Fresh Docker installs use Eleon from `.env.example`. An existing
installation without the variable keeps Original.

The product profile controls the navigation mark, default name, page titles,
login heading, favicons, push icon and installable-app manifest. A custom
business name/logo in Settings → Branding remains a separate workspace setting.
The reserved default names “Case Workspace” and “Eleon” follow the profile.

An administrator can append `&brand_preview=eleon` to a case URL for an Eleon
navigation/favicon preview on that page only. It does not change the saved
profile or the installed-app identity.

Reload after switching. Chrome/Edge may take longer to update an already
installed app's taskbar name/icon; reopening or reinstalling the shortcut can
refresh that browser-managed copy. Browser pages immediately use the selected
assets after reload.
