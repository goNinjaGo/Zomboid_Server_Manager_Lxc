# LXC Linux Installation

This deployment runs the Project Zomboid dedicated server in a system LXC container. The Laravel app, PostgreSQL, Redis (if configured), and reverse proxy run on the host. Docker Compose remains a separate deployment option.

The installer targets Debian 12 (Bookworm) x86-64 hosts with systemd and the standard `lxc-net` bridge. It creates a privileged LXC container named `pz-game`, uses the private bridge address `10.0.3.10`, and forwards game UDP ports 16261–16262. It refuses to overwrite an existing container or reuse host UID/GID 2000.

## 1. Install host prerequisites and the LXC game container

Install the host PHP, PostgreSQL, Composer, Node.js, and Nginx prerequisites for the panel first. Ensure the PHP service account exists (usually `www-data`), then run the installer from the repository root:

```bash
sudo bash native/install-lxc.sh
```

The installer downloads a Debian 12 root filesystem, enables the LXC bridge, installs Java and SteamCMD inside the guest, shares the game data and server directories with the host, and enables the game service inside the guest. The game files download from SteamCMD the first time the LXC container starts.

Review `/etc/zomboid-manager/server.env` and set strong values for `PZ_ADMIN_PASSWORD` and `PZ_RCON_PASSWORD`. Keep RCON private; the host panel reaches it over the LXC bridge at `10.0.3.10:27015`.

## 2. Configure the panel

Install the application dependencies and create its environment file as described in [Native Linux Installation](installation-native.md). Set these values in `app/.env`:

```dotenv
PZ_RUNTIME=lxc
PZ_LXC_MANAGER=/usr/local/sbin/zomboid-lxc-manager
PZ_RCON_HOST=10.0.3.10
PZ_RCON_PORT=27015
PZ_RCON_PASSWORD=THE_SAME_VALUE_AS_PZ_RCON_PASSWORD
PZ_DATA_PATH=/var/lib/zomboid-manager/data
PZ_SERVER_PATH=/opt/zomboid/server
PZ_MAP_TILES_PATH=/var/lib/zomboid-manager/map-tiles
BACKUP_PATH=/var/lib/zomboid-manager/backups
LUA_BRIDGE_PATH=/var/lib/zomboid-manager/data/Lua
```

Use matching `PZ_SERVER_NAME` and `PZ_RCON_PASSWORD` values in both environment files. Configure the panel’s PostgreSQL connection and administrator credentials, then run migrations and create the admin account. The installer adds the PHP service account to the `pzmanager` group; restart PHP-FPM after that change so the process gets the new group membership.

## 3. Start the container and panel

The installer enables the `pz-server` unit inside the guest and enables `zomboid-lxc-container.service` on the host to start and stop the guest at boot and shutdown. The panel can start, stop, restart, and read logs from the game service through a root-owned command wrapper restricted by sudoers:

```bash
sudo lxc-start --name pz-game
sudo lxc-info --name pz-game
sudo lxc-attach --name pz-game -- systemctl status pz-server
```

The panel starts/stops `pz-server` inside the guest and leaves the guest itself running. A host reboot starts the LXC guest again.

## Networking and firewall

The guest uses the `lxcbr0` NAT bridge with static address `10.0.3.10`; the DHCP pool begins at `10.0.3.100`. The host `zomboid-lxc-container.service` starts the guest at boot and shuts it down cleanly at host shutdown. The installer also installs `zomboid-lxc-port-forward.service`, which forwards the game’s UDP ports to the guest. Open UDP 16261–16262 in the host firewall and on the router for internet-facing servers:

```bash
make expose
```

Do not expose TCP 27015. If the host’s default route uses a non-standard interface, set `PZ_UPLINK` for the port-forward service in a systemd drop-in. Review host firewall rules after installing; firewalld or custom nftables policies may need an explicit forward allowance for `lxcbr0`.

## Shared paths and security

The app and guest share `/var/lib/zomboid-manager/data` and `/opt/zomboid/server`; the game scripts are mounted read-only from the repository. The guest runs the game process as UID/GID 2000, while the host app user gets write access through the `pzmanager` group. The LXC container itself uses the host’s regular privileged LXC mode, so keep the host patched and do not give untrusted users access to LXC tools or the wrapper.

The sudoers rule grants the app account access only to `/usr/local/sbin/zomboid-lxc-manager`. That wrapper validates operations and log arguments before invoking LXC or systemd commands.

## Updating and backups

The panel writes Steam branch and update markers into the shared data directory. Restarting the guest service applies those markers through the existing `native/run-server.sh` startup script. Backups, world imports, configuration edits, and Lua bridge files operate on the shared host paths.
