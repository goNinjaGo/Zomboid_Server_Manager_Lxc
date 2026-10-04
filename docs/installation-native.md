# Native Linux Installation (No Docker)

This deployment runs the Laravel panel, PostgreSQL, and the Project Zomboid server directly on a Debian or Ubuntu host. `systemd` controls the game server; the panel accesses its files on disk and sends RCON to localhost. The included installer needs root access and is intended for a fresh server.

## 1. Install host prerequisites

Clone the repository to `/opt/zomboid-manager`, then run:

```bash
sudo bash native/install-debian.sh
```

Install SteamCMD from your distribution's package source if the installer reports that it is missing. On Ubuntu, enable `multiverse`; on Debian, enable the appropriate Steam repository for your release. Then check that `/usr/games/steamcmd` exists.

## 2. Configure the game server

Edit `/etc/zomboid-manager/server.env`. Set unique values for `PZ_ADMIN_PASSWORD` and `PZ_RCON_PASSWORD`. The `pz-server` service downloads the dedicated server from Steam on its first start. It uses `/opt/zomboid/server` for installed game files and `/var/lib/zomboid-manager/data` for saves and configuration.

```bash
sudo systemctl enable --now pz-server
sudo journalctl -u pz-server -f
```

The game listens on UDP 16261 and 16262 by default. Open those ports in the host firewall and router if players connect over the internet. Keep TCP 27015 (RCON) private; the panel connects over loopback.

## 3. Configure the panel

Install dependencies and build the frontend from the repository root:

```bash
cd app
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.example .env
php artisan key:generate
```

Edit `app/.env` and set at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://YOUR_SERVER_IP
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=zomboid
DB_USERNAME=zomboid
DB_PASSWORD=CHOOSE_A_DATABASE_PASSWORD
QUEUE_CONNECTION=database
CACHE_STORE=file
SESSION_DRIVER=file
PZ_RUNTIME=systemd
PZ_RCON_HOST=127.0.0.1
PZ_RCON_PASSWORD=THE_SAME_VALUE_AS_PZ_RCON_PASSWORD
PZ_DATA_PATH=/var/lib/zomboid-manager/data
PZ_SERVER_PATH=/opt/zomboid/server
PZ_MAP_TILES_PATH=/var/lib/zomboid-manager/map-tiles
BACKUP_PATH=/var/lib/zomboid-manager/backups
LUA_BRIDGE_PATH=/var/lib/zomboid-manager/data/Lua
ADMIN_USERNAME=admin
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=CHOOSE_A_STRONG_PASSWORD
```

Create a local PostgreSQL role/database (use the same password in `.env`):

```bash
sudo -u postgres psql -c "CREATE USER zomboid WITH PASSWORD 'CHOOSE_A_DATABASE_PASSWORD';"
sudo -u postgres createdb -O zomboid zomboid
```

Set the same `PZ_SERVER_NAME` in `/etc/zomboid-manager/server.env` and `app/.env`. Run `php artisan migrate --force` and `php artisan zomboid:create-admin` to prepare the database and create the admin account. Ensure the web user is a member of `pzmanager`; restart the service after changing its groups.

The `PZ_RUNTIME=systemd` backend allows the web app to control only the `pz-server` unit and read its journal through a narrowly scoped sudoers rule installed by the script. The app and game server use shared group access to the data files.

## 4. Start the panel

The panel units expect the repository at `/opt/zomboid-manager`, as specified above. Install and enable them with:

```bash
sudo cp native/zomboid-manager-web.service native/zomboid-manager-queue.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now zomboid-manager-web zomboid-manager-queue
```

Configure `native/nginx.conf` as an Nginx site (`/etc/nginx/sites-available/zomboid-manager`), enable it with a symlink in `sites-enabled`, and reload Nginx. For public HTTPS, configure a host-installed reverse proxy such as Caddy or Nginx with TLS. The game service and app can be operated with `systemctl`; logs are available via `journalctl -u pz-server` and `journalctl -u zomboid-manager-web`.

## Operational notes

- Updating or switching the Steam branch through the panel writes a marker in the data directory. Restarting the game service applies the branch and processes an update marker.
- The installer creates `/etc/sudoers.d/zomboid-manager` so the panel can manage the single game-server service. Review that file before enabling remote panel access.
- This guide targets Debian/Ubuntu x86-64 hosts. ARM64 and native Windows need separate game-server runtime support.
