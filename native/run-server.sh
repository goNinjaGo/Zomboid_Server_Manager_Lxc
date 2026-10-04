#!/usr/bin/env bash
set -euo pipefail

DATA_DIR=${PZ_DATA_PATH:-/var/lib/zomboid-manager/data}
SERVER_DIR=${PZ_SERVER_PATH:-/opt/zomboid/server}
REPO_DIR=${PZ_MANAGER_PATH:-/opt/zomboid-manager}
STEAMCMD=${STEAMCMD:-/usr/games/steamcmd}
export STEAMCMD
SERVER_NAME=${PZ_SERVER_NAME:-ZomboidServer}
BRANCH_FILE="$DATA_DIR/.steam_branch"
UPDATE_FILE="$DATA_DIR/.force_update"

mkdir -p "$DATA_DIR" "$DATA_DIR/Server" "$DATA_DIR/Lua" "$DATA_DIR/Saves"
mkdir -p "$DATA_DIR/mods"
ln -sfn "$REPO_DIR/game-server/mods/ZomboidManager" "$DATA_DIR/mods/ZomboidManager"
export PZ_CONFIG_DIR="$DATA_DIR" PZ_INSTALL_DIR="$SERVER_DIR"

branch=${PZ_STEAM_BRANCH:-public}
if [[ -s "$BRANCH_FILE" ]]; then branch=$(<"$BRANCH_FILE"); fi
if [[ "$branch" == public ]]; then
  beta_args=()
else
  beta_args=(-beta "$branch")
fi

if [[ ! -x "$SERVER_DIR/start-server.sh" || -f "$UPDATE_FILE" ]]; then
  rm -f "$UPDATE_FILE"
  "$STEAMCMD" +@sSteamCmdForcePlatformType linux +force_install_dir "$SERVER_DIR" +login anonymous +app_update 380870 "${beta_args[@]}" validate +quit
fi

CONFIGURE_SCRIPT=${PZ_CONFIGURE_SCRIPT:-$(dirname "$0")/../game-server/configure-server.sh}
bash "$CONFIGURE_SCRIPT"
exec "$SERVER_DIR/start-server.sh" -servername "$SERVER_NAME"
