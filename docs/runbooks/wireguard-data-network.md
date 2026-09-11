# WireGuard network for the VTSA data server

This runbook creates an encrypted, narrow network between the two application servers and the data server. The data server is the fixed WireGuard endpoint. The load balancer is not a peer because it does not connect directly to PostgreSQL, Redis, or the private MinIO API.

## Topology

| Server | Public address | WireGuard address | Role |
| --- | --- | --- | --- |
| Data Server | `187.53.134.127` | `10.77.0.1/24` | WireGuard hub and data services |
| App Server 1 | `187.77.130.156` | `10.77.0.2/32` | WireGuard peer |
| App Server 2 | `187.53.134.122` | `10.77.0.3/32` | WireGuard peer |

The `10.77.0.0/24` range is a proposed private subnet. Confirm on all three servers that it does not overlap an existing interface, Docker network, private network, or route:

```bash
ip -4 address show
ip -4 route show
docker network ls
ip -4 route show | grep -F '10.77.0.0/24' || true
```

Continue only when the final command returns no existing route. If the range is already in use, choose another unused RFC 1918 subnet and change every `10.77.0.x` value in this runbook consistently.

No IP forwarding or NAT is needed. The data server is the destination for this traffic rather than a router to another network.

## 1. Configure the Hostinger firewall

On the data server's Hostinger firewall, add these inbound rules:

| Protocol and port | Allowed source | Purpose |
| --- | --- | --- |
| UDP `51820` | `187.77.130.156/32` | App Server 1 WireGuard handshake |
| UDP `51820` | `187.53.134.122/32` | App Server 2 WireGuard handshake |
| TCP `22` | trusted administration IPs only | SSH administration |

Do not expose TCP `5432`, `6379`, `6380`, `9000`, `9001`, `9100`, or `9101` on the public interface. The app servers initiate the tunnel, so they do not need a new public inbound WireGuard rule. Their outbound UDP traffic to `187.53.134.127:51820` must be allowed.

## 2. Install WireGuard and generate one keypair per server

Run this block separately on the data server, App Server 1, and App Server 2:

```bash
sudo apt update
sudo apt install -y wireguard wireguard-tools
sudo install -d -m 0700 /etc/wireguard

if sudo test -e /etc/wireguard/wg0.key; then
  echo 'Existing /etc/wireguard/wg0.key found; stop and inspect it before continuing.'
else
  sudo sh -c 'umask 077; wg genkey > /etc/wireguard/wg0.key; wg pubkey < /etc/wireguard/wg0.key > /etc/wireguard/wg0.pub'
fi

sudo chmod 600 /etc/wireguard/wg0.key
sudo chmod 644 /etc/wireguard/wg0.pub
sudo cat /etc/wireguard/wg0.pub
```

Record the three displayed **public** keys using these labels:

```text
DATA_SERVER_PUBLIC_KEY=
APP_SERVER_1_PUBLIC_KEY=
APP_SERVER_2_PUBLIC_KEY=
```

The Base64-looking WireGuard public keys are safe to exchange between these three servers. Never display, copy between servers, commit, or share `/etc/wireguard/wg0.key`; that file is the private key.

## 3. Configure the data server

On the data server (`187.53.134.127`), create the interface configuration:

```bash
sudo nano /etc/wireguard/wg0.conf
```

Paste this configuration, replacing only the two application-server public-key placeholders:

```ini
[Interface]
Address = 10.77.0.1/24
ListenPort = 51820
PostUp = wg set %i private-key /etc/wireguard/%i.key

[Peer]
# App Server 1
PublicKey = REPLACE_WITH_APP_SERVER_1_PUBLIC_KEY
AllowedIPs = 10.77.0.2/32

[Peer]
# App Server 2
PublicKey = REPLACE_WITH_APP_SERVER_2_PUBLIC_KEY
AllowedIPs = 10.77.0.3/32
```

Protect and validate it:

```bash
sudo chown root:root /etc/wireguard/wg0.conf /etc/wireguard/wg0.key
sudo chmod 600 /etc/wireguard/wg0.conf /etc/wireguard/wg0.key

if sudo grep -q REPLACE_WITH /etc/wireguard/wg0.conf; then
  echo 'ERROR: unresolved public-key placeholders remain'
else
  sudo wg-quick strip wg0 >/dev/null && echo 'Data-server WireGuard configuration is valid'
fi
```

If UFW is active, add narrow interface rules without changing its existing SSH policy:

```bash
sudo ufw status verbose
sudo ufw allow proto udp from 187.77.130.156 to any port 51820
sudo ufw allow proto udp from 187.53.134.122 to any port 51820

for peer in 10.77.0.2 10.77.0.3; do
  for port in 5432 6379 6380 9000 9100; do
    sudo ufw allow in on wg0 from "$peer" to 10.77.0.1 port "$port" proto tcp
  done
done
```

Do not run `ufw enable` during a remote session unless SSH access has been explicitly preserved and tested. If UFW is inactive, keep it inactive for now and rely on the Hostinger firewall plus the data services' `10.77.0.1` bind address until a complete host-firewall policy is reviewed.

Start the data-server interface:

```bash
sudo systemctl enable --now wg-quick@wg0
sudo systemctl status wg-quick@wg0 --no-pager
ip -4 address show dev wg0
sudo wg show wg0
```

The address output must show `10.77.0.1/24`. A handshake is not expected until an app-server peer starts.

## 4. Configure App Server 1

On App Server 1 (`187.77.130.156`):

```bash
sudo nano /etc/wireguard/wg0.conf
```

Paste this configuration and replace the data-server public-key placeholder:

```ini
[Interface]
Address = 10.77.0.2/32
PostUp = wg set %i private-key /etc/wireguard/%i.key

[Peer]
# Data Server
PublicKey = REPLACE_WITH_DATA_SERVER_PUBLIC_KEY
Endpoint = 187.53.134.127:51820
AllowedIPs = 10.77.0.1/32
PersistentKeepalive = 25
```

Activate and test it:

```bash
sudo chown root:root /etc/wireguard/wg0.conf /etc/wireguard/wg0.key
sudo chmod 600 /etc/wireguard/wg0.conf /etc/wireguard/wg0.key
if sudo grep -q REPLACE_WITH /etc/wireguard/wg0.conf; then
  echo 'ERROR: unresolved public-key placeholder remains; do not start WireGuard'
else
  sudo wg-quick strip wg0 >/dev/null
  sudo systemctl enable --now wg-quick@wg0
  ip route get 10.77.0.1
  sudo wg show wg0
fi
```

The route output must include `dev wg0`, and `wg show` must report a recent handshake and transferred bytes. A ping test is intentionally not required because a narrow host firewall may reject ICMP while allowing the required data ports.

## 5. Configure App Server 2

On App Server 2 (`187.53.134.122`):

```bash
sudo nano /etc/wireguard/wg0.conf
```

Paste this configuration and replace the data-server public-key placeholder:

```ini
[Interface]
Address = 10.77.0.3/32
PostUp = wg set %i private-key /etc/wireguard/%i.key

[Peer]
# Data Server
PublicKey = REPLACE_WITH_DATA_SERVER_PUBLIC_KEY
Endpoint = 187.53.134.127:51820
AllowedIPs = 10.77.0.1/32
PersistentKeepalive = 25
```

Activate and test it:

```bash
sudo chown root:root /etc/wireguard/wg0.conf /etc/wireguard/wg0.key
sudo chmod 600 /etc/wireguard/wg0.conf /etc/wireguard/wg0.key
if sudo grep -q REPLACE_WITH /etc/wireguard/wg0.conf; then
  echo 'ERROR: unresolved public-key placeholder remains; do not start WireGuard'
else
  sudo wg-quick strip wg0 >/dev/null
  sudo systemctl enable --now wg-quick@wg0
  ip route get 10.77.0.1
  sudo wg show wg0
fi
```

## 6. Verify both peers from the data server

Return to the data server:

```bash
sudo wg show wg0
```

Both peers must have a recent handshake. `latest handshake` should be measured in seconds or a few minutes, not `never`.

At this point, use `10.77.0.1` as `DATA_BIND_ADDRESS`, `DB_HOST`, `REDIS_HOST`, and the hostname portion of each internal MinIO endpoint in the staging/production deployment runbook.

After starting the data stack, test its published ports from both app servers:

```bash
for port in 5432 6379 6380 9000 9100; do
  timeout 3 bash -c "</dev/tcp/10.77.0.1/$port" \
    && echo "reachable: 10.77.0.1:$port" \
    || echo "blocked: 10.77.0.1:$port"
done
```

MinIO console ports `9001` and `9101` should remain unreachable from the app servers because they bind only to localhost on the data server.

## Troubleshooting

Inspect the interface and service without revealing private keys:

```bash
sudo systemctl status wg-quick@wg0 --no-pager
sudo journalctl -u wg-quick@wg0 -n 100 --no-pager
sudo wg show wg0
ip -4 route get 10.77.0.1
```

Typical causes of no handshake are:

- UDP `51820` is missing from the data server's Hostinger firewall;
- the app peer has the wrong data-server public key or endpoint;
- the data server has the wrong app-server public key;
- duplicate WireGuard addresses or overlapping `AllowedIPs` were used;
- an app server cannot send outbound UDP;
- the proposed `10.77.0.0/24` subnet conflicts with an existing route.

Do not paste `wg0.conf`, `wg0.key`, populated application environment files, or command output containing credentials into public issues or chat. Public keys, interface addresses, handshake times, and byte counters are sufficient for most diagnosis.

## Updating a peer later

After changing a public key or adding a new peer, validate the configuration and restart the interface during a controlled maintenance window:

```bash
sudo wg-quick strip wg0 >/dev/null
sudo systemctl restart wg-quick@wg0
sudo wg show wg0
```

Changing only peer definitions can also be applied with `systemctl reload wg-quick@wg0`, but a restart is required when routes or interface addresses change.
