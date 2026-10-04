#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then echo "Run this installer with sudo." >&2; exit 1; fi
ROOT=$(cd "$(dirname "$0")/.." && pwd)
APP_USER=${APP_USER:-www-data}

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl unzip tar sudo postgresql postgresql-contrib nginx \
  php-cli php-fpm php-pgsql php-sqlite3 php-xml php-mbstring php-curl php-zip php-bcmath php-intl composer nodejs npm

if ! getent group pzmanager >/dev/null; then groupadd --system pzmanager; fi
id pzserver >/dev/null 2>&1 || useradd --system --home-dir /var/lib/zomboid-manager --create-home --shell /usr/sbin/nologin pzserver
usermod -a -G pzmanager "$APP_USER"
usermod -a -G pzmanager pzserver

install -d -o pzserver -g pzmanager -m 2775 /opt/zomboid/server
install -d -o root -g root -m 0755 /opt/zomboid
install -d -o pzserver -g pzmanager -m 2775 /var/lib/zomboid-manager/data
install -d -o "$APP_USER" -g pzmanager -m 2775 /var/lib/zomboid-manager/backups /var/lib/zomboid-manager/map-tiles
install -d -o pzserver -g pzmanager -m 2775 /var/lib/zomboid-manager/data/Lua
install -d -o root -g root -m 0755 /etc/zomboid-manager
install -m 0755 "$ROOT/native/run-server.sh" /opt/zomboid/native-run-server.sh
install -m 0644 "$ROOT/game-server/configure-server.sh" /opt/zomboid/configure-server.sh

if [[ ! -x /usr/games/steamcmd ]]; then
  echo "SteamCMD was not installed by your distribution. Install SteamCMD and rerun this script." >&2
fi

install -m 0644 "$ROOT/native/pz-server.service" /etc/systemd/system/pz-server.service
install -d -m 0755 /etc/sudoers.d
printf '%s ALL=(root) NOPASSWD: /usr/bin/systemctl start pz-server, /usr/bin/systemctl stop pz-server, /usr/bin/systemctl restart pz-server, /usr/bin/systemctl show pz-server, /usr/bin/journalctl -u pz-server *\n' "$APP_USER" > /etc/sudoers.d/zomboid-manager
chmod 0440 /etc/sudoers.d/zomboid-manager
visudo -cf /etc/sudoers.d/zomboid-manager

if [[ ! -f "$ROOT/app/.env" ]]; then cp "$ROOT/app/.env.example" "$ROOT/app/.env"; fi
if [[ ! -f /etc/zomboid-manager/server.env ]]; then
  install -m 0640 -o root -g pzmanager "$ROOT/native/server.env.example" /etc/zomboid-manager/server.env
fi
chown root:pzmanager /etc/zomboid-manager/server.env
chmod 0640 /etc/zomboid-manager/server.env
chown -R "$APP_USER":pzmanager "$ROOT/app/storage" "$ROOT/app/bootstrap/cache"
chmod -R g+rwX "$ROOT/app/storage" "$ROOT/app/bootstrap/cache"

systemctl daemon-reload
echo "Native prerequisites installed. Follow docs/installation-native.md to configure the database, environment, PHP app, and web server."
