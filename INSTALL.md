# Install Eleon Community

Eleon is a self-hosted application for Outlook/Gmail email, cases, collaboration
and optional AI. All installed software features are included without a paid key.

## Start from source

1. Install Git and [Docker Desktop](https://www.docker.com/products/docker-desktop/)
   on Windows/macOS, or Docker Engine with the Compose plugin on Linux.
2. On Windows/macOS, **open Docker Desktop** and wait for **Engine running**.
   Follow any WSL update or restart prompt on Windows. Use Linux containers;
   if the tray menu offers **Switch to Linux containers**, select it.
   Keep Docker Desktop running while using Eleon.
3. Run `docker info` in your terminal. It must show server information without
   a connection error before you start Eleon. On Linux, ensure the Docker service
   is running and your user can access it.
4. Download or clone the source into a folder of your own, for example `C:\dev`
   on Windows (not `C:\Windows\System32`, where an administrator PowerShell opens):

   ```sh
   git clone https://github.com/giorgosbil/eleon.git
   cd eleon
   ```

   Run these once. If the source is already downloaded, open the folder containing
   `Start.ps1` and `compose.yaml` instead. Do not run `cd eleon` again inside it.

5. On Windows, run in PowerShell:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\Start.ps1
   ```

   On Linux/macOS:

   ```sh
   sh start.sh
   ```

## Docker cannot connect on Windows

An error mentioning `dockerDesktopLinuxEngine` or a missing named pipe means
Docker's Linux engine is unavailable. Eleon has not started yet.

Open Docker Desktop **inside the Windows installation where you run Eleon**.
For a VM, opening Docker on the main PC does not start it inside the guest.
Wait for **Engine running**, then try `docker info` again. Follow any WSL prompt;
if needed, run `wsl --update` in administrator PowerShell, restart Windows and
reopen Docker Desktop. If it still fails, check the error in Docker Desktop.

## Testing in a Hyper-V VM

Docker's Linux engine inside a Windows VM needs nested virtualization.

1. Shut down the guest from its Start menu. It must be **Off**, not saved or paused.
2. On the **main PC**, open PowerShell as administrator. List the VM names:

   ```powershell
   Get-VM | Select-Object Name, State
   ```

3. Replace the example name below with your VM's name:

   ```powershell
   Set-VMProcessor -VMName "Your VM name" -ExposeVirtualizationExtensions $true
   Set-VMNetworkAdapter -VMName "Your VM name" -MacAddressSpoofing On
   Set-VMMemory -VMName "Your VM name" -DynamicMemoryEnabled $false -StartupBytes 8GB
   ```

   The second line lets Docker's containers reach the network; the third gives the
   VM fixed memory, which Docker needs (4GB is the minimum).

4. Start the VM, open Docker Desktop **inside it**, and wait for **Engine running**.
5. Run `docker info` inside the VM, then run Eleon's start command above.

See [Microsoft's nested virtualization requirements](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/enable-nested-virtualization)
and [Docker's VM setup guidance](https://docs.docker.com/desktop/setup/vm-vdi/).

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
