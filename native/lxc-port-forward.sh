#!/usr/bin/env bash
set -euo pipefail

BRIDGE=${LXC_BRIDGE:-lxcbr0}
CONTAINER_IP=${PZ_LXC_IP:-10.0.3.10}
UPLINK=${PZ_UPLINK:-$(ip -o route get 1.1.1.1 | awk '{ for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i + 1); exit } }')}
GAME_PORT=${PZ_GAME_PORT:-16261}
DIRECT_PORT=${PZ_DIRECT_PORT:-16262}

if [[ -z "$UPLINK" ]]; then
    echo "Could not determine the outbound network interface; set PZ_UPLINK." >&2
    exit 1
fi

for port in "$GAME_PORT" "$DIRECT_PORT"; do
    [[ "$port" =~ ^[0-9]{1,5}$ ]] || { echo "Invalid UDP port: $port" >&2; exit 1; }
    port_number=$((10#$port))
    (( port_number >= 1 && port_number <= 65535 )) || { echo "Invalid UDP port: $port" >&2; exit 1; }
    iptables -t nat -C PREROUTING -i "$UPLINK" -p udp --dport "$port" -j DNAT --to-destination "$CONTAINER_IP:$port" 2>/dev/null \
        || iptables -t nat -A PREROUTING -i "$UPLINK" -p udp --dport "$port" -j DNAT --to-destination "$CONTAINER_IP:$port"
    iptables -C FORWARD -i "$UPLINK" -o "$BRIDGE" -p udp -d "$CONTAINER_IP" --dport "$port" -j ACCEPT 2>/dev/null \
        || iptables -I FORWARD 1 -i "$UPLINK" -o "$BRIDGE" -p udp -d "$CONTAINER_IP" --dport "$port" -j ACCEPT
done

iptables -C FORWARD -i "$BRIDGE" -o "$UPLINK" -s "$CONTAINER_IP" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null \
    || iptables -I FORWARD 1 -i "$BRIDGE" -o "$UPLINK" -s "$CONTAINER_IP" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
