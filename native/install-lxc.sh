#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run this installer with sudo." >&2
    exit 1
fi

ROOT=$(cd "$(dirname "$0")/.." && pwd)
APP_USER=${APP_USER:-www-data}
CONTAINER=${PZ_LXC_NAME:-pz-game}
CONTAINER_IP=${PZ_LXC_IP:-10.0.3.10}
DATA_DIR=/var/lib/zomboid-manager/data
SERVER_DIR=/opt/zomboid/server
LXC_DIR="/var/lib/lxc/$CONTAINER"
ROOTFS="$LXC_DIR/rootfs"

if [[ "$CONTAINER" != pz-game ]]; then
    echo "This installer currently supports the fixed container name pz-game." >&2
    exit 1
fi
if [[ "$CONTAINER_IP" != 10.0.3.10 ]]; then
    echo "This installer currently uses the reserved LXC bridge address 10.0.3.10." >&2
    exit 1
fi
if ! id "$APP_USER" >/dev/null 2>&1; then
    echo "Application user '$APP_USER' does not exist. Install the app/PHP runtime first or set APP_USER." >&2
    exit 1
fi
if [[ -e "$LXC_DIR" ]]; then
    echo "LXC container '$CONTAINER' already exists at $LXC_DIR; refusing to overwrite it." >&2
    exit 1
fi
if getent group pzmanager >/dev/null || id pzserver >/dev/null 2>&1; then
    echo "A pzmanager group or pzserver user already exists; this installer requires a fresh LXC identity setup." >&2
    exit 1
fi
if getent group 2000 >/dev/null || getent passwd 2000 >/dev/null; then
    echo "UID/GID 2000 is already in use on the host; adjust the installer identity mapping before proceeding." >&2
    exit 1
fi

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    ca-certificates curl sudo lxc lxc-templates bridge-utils iptables uidmap

groupadd --gid 2000 pzmanager
useradd --uid 2000 --gid pzmanager --home-dir /var/lib/zomboid-manager --create-home --shell /usr/sbin/nologin pzserver
usermod -a -G pzmanager "$APP_USER"

install -d -o pzserver -g pzmanager -m 2775 "$SERVER_DIR" "$DATA_DIR" "$DATA_DIR/Lua"
install -d -o "$APP_USER" -g pzmanager -m 2775 \
    /var/lib/zomboid-manager/backups /var/lib/zomboid-manager/map-tiles
install -d -o root -g root -m 0755 /etc/zomboid-manager /opt/zomboid-manager

if [[ ! -f /etc/default/lxc-net ]]; then
    install -m 0644 /dev/null /etc/default/lxc-net
fi
set_lxc_default() {
    local key=$1 value=$2
    if grep -q "^${key}=" /etc/default/lxc-net; then
        sed -i "s|^${key}=.*|${key}=\"${value}\"|" /etc/default/lxc-net
    else
        printf '%s="%s"\n' "$key" "$value" >> /etc/default/lxc-net
    fi
}
set_lxc_default USE_LXC_BRIDGE true
set_lxc_default LXC_BRIDGE lxcbr0
set_lxc_default LXC_ADDR 10.0.3.1
set_lxc_default LXC_NETMASK 255.255.255.0
set_lxc_default LXC_NETWORK 10.0.3.0/24
set_lxc_default LXC_DHCP_RANGE 10.0.3.100,10.0.3.254
set_lxc_default LXC_DHCP_MAX 155
systemctl enable --now lxc-net.service

lxc-create --name "$CONTAINER" --template download -- \
    --dist debian --release bookworm --arch amd64
if awk -F: '$3 == 2000 { found = 1 } END { exit !found }' "$ROOTFS/etc/passwd" \
    || awk -F: '$3 == 2000 { found = 1 } END { exit !found }' "$ROOTFS/etc/group"; then
    echo "UID/GID 2000 is already reserved in the container image; remove $LXC_DIR and adjust the identity mapping." >&2
    exit 1
fi
install -d -o root -g root -m 0755 "$ROOTFS/etc/zomboid-manager"
CONFIG="$LXC_DIR/config"
sed -i '/^lxc.net.0\./d' "$CONFIG"
cat >> "$CONFIG" <<EOF

# Isolated veth on the managed LXC bridge. .10 is outside the DHCP pool.
lxc.uts.name = $CONTAINER
lxc.net.0.type = veth
lxc.net.0.link = lxcbr0
lxc.net.0.flags = up
lxc.net.0.name = eth0
lxc.net.0.ipv4.address = $CONTAINER_IP/24
lxc.net.0.ipv4.gateway = 10.0.3.1
lxc.cap.drop = mac_admin mac_override sys_module sys_time sys_rawio
lxc.mount.entry = $DATA_DIR var/lib/zomboid-manager/data none bind,create=dir 0 0
lxc.mount.entry = $SERVER_DIR opt/zomboid/server none bind,create=dir 0 0
lxc.mount.entry = $ROOT/game-server opt/zomboid-manager/game-server none bind,ro,create=dir 0 0
lxc.mount.entry = /etc/zomboid-manager/server.env etc/zomboid-manager/server.env none bind,ro,create=file 0 0
EOF

if [[ ! -f /etc/zomboid-manager/server.env ]]; then
    install -m 0640 -o root -g pzmanager "$ROOT/native/server.env.example" /etc/zomboid-manager/server.env
fi
chown root:pzmanager /etc/zomboid-manager/server.env
chmod 0640 /etc/zomboid-manager/server.env

install -D -o root -g root -m 0755 "$ROOT/native/run-server.sh" "$ROOTFS/usr/local/lib/zomboid-manager/run-server.sh"
install -D -o root -g root -m 0644 "$ROOT/game-server/configure-server.sh" "$ROOTFS/usr/local/lib/zomboid-manager/configure-server.sh"
install -D -o root -g root -m 0644 "$ROOT/native/lxc/pz-server.service" "$ROOTFS/etc/systemd/system/pz-server.service"

# SteamCMD is in Debian's non-free repository and needs its 32-bit runtime.
lxc-start --name "$CONTAINER"
lxc-wait --name "$CONTAINER" --state RUNNING --timeout 60
lxc-attach --name "$CONTAINER" --clear-env -- /bin/bash -ceu '
    if [[ -f /etc/apt/sources.list.d/debian.sources ]]; then
        sed -i -E "s/^Components: .*/Components: main contrib non-free non-free-firmware/" /etc/apt/sources.list.d/debian.sources
    else
        printf "deb http://deb.debian.org/debian bookworm main contrib non-free non-free-firmware\\n" > /etc/apt/sources.list
        printf "deb http://security.debian.org/debian-security bookworm-security main contrib non-free non-free-firmware\\n" >> /etc/apt/sources.list
        printf "deb http://deb.debian.org/debian bookworm-updates main contrib non-free non-free-firmware\\n" >> /etc/apt/sources.list
    fi
    dpkg --add-architecture i386
    apt-get update
    printf "steam steam/question select I AGREE\\nsteam steam/license note \\n" | debconf-set-selections
    apt-get install -y ca-certificates openjdk-17-jre-headless steamcmd
    groupadd --gid 2000 pzmanager
    useradd --uid 2000 --gid pzmanager --home-dir /var/lib/zomboid-manager --create-home --shell /usr/sbin/nologin pzserver
    systemctl enable pz-server.service
'
lxc-stop --name "$CONTAINER" --timeout 90

install -o root -g root -m 0755 "$ROOT/native/zomboid-lxc-manager" /usr/local/sbin/zomboid-lxc-manager
install -o root -g root -m 0755 "$ROOT/native/lxc-port-forward.sh" /usr/local/sbin/zomboid-lxc-port-forward
install -o root -g root -m 0644 "$ROOT/native/zomboid-lxc-container.service" /etc/systemd/system/zomboid-lxc-container.service
install -o root -g root -m 0644 "$ROOT/native/zomboid-lxc-port-forward.service" /etc/systemd/system/zomboid-lxc-port-forward.service
install -d -m 0755 /etc/sudoers.d
printf '%s ALL=(root) NOPASSWD: /usr/local/sbin/zomboid-lxc-manager\n' "$APP_USER" > /etc/sudoers.d/zomboid-manager-lxc
chmod 0440 /etc/sudoers.d/zomboid-manager-lxc
visudo -cf /etc/sudoers.d/zomboid-manager-lxc

systemctl daemon-reload
systemctl enable --now zomboid-lxc-container.service
systemctl enable --now zomboid-lxc-port-forward.service
echo "LXC game container created. Configure app/.env with PZ_RUNTIME=lxc and the host paths documented in docs/installation-lxc.md."
