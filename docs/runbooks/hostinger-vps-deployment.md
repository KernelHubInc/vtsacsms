# Hostinger VPS Docker deployment

This runbook deploys VTSA CSMS from the private GitHub repository to one Hostinger VPS. It is a production-mode single-server baseline, not a high-availability topology. PostgreSQL, Redis, and MinIO are private to Docker; Caddy is the only public entry point and obtains TLS certificates automatically.

## 1. Prepare the VPS and DNS

Use a Hostinger VPS with the Ubuntu 24.04 Docker template, or install current Docker Engine and the Docker Compose v2 plugin. Allocate enough disk for images, PostgreSQL, MinIO objects, and backups. Configure the VPS firewall to allow SSH from trusted administration addresses and public TCP 80/443 plus UDP 443. Do **not** expose ports 5432, 6379, 9000, or 9001.

Create three DNS records pointing to the VPS public IP:

- `csms.your-domain.example` for Laravel/public/admin/operator/API traffic.
- `ocpp.your-domain.example` for charger WebSocket traffic.
- `storage.your-domain.example` for object-storage downloads and uploads.

Do not proceed until all three names resolve to the VPS. Caddy needs ports 80 and 443 to issue certificates.

## 2. Give the VPS read-only access to the private repository

On the VPS, generate a repository-specific deploy key:

```bash
sudo install -d -m 0700 /opt/vtsa-csms-keys
sudo ssh-keygen -t ed25519 -C "vtsa-csms-hostinger-deploy" -f /opt/vtsa-csms-keys/id_ed25519 -N ""
sudo cat /opt/vtsa-csms-keys/id_ed25519.pub
```

In GitHub, open `KernelHubInc/vtsacsms` → **Settings** → **Deploy keys** → **Add deploy key**. Paste the public key and leave write access disabled. Then configure SSH and clone:

```bash
sudo install -d -m 0700 /root/.ssh
sudo ssh-keyscan -t ed25519 github.com | sudo tee -a /root/.ssh/known_hosts >/dev/null
sudo chmod 600 /root/.ssh/known_hosts
sudo tee /root/.ssh/config >/dev/null <<'EOF'
Host github-vtsa
    HostName github.com
    User git
    IdentityFile /opt/vtsa-csms-keys/id_ed25519
    IdentitiesOnly yes
EOF
sudo chmod 600 /root/.ssh/config
sudo git clone git@github-vtsa:KernelHubInc/vtsacsms.git /opt/vtsa-csms
cd /opt/vtsa-csms
```

Verify GitHub's published SSH fingerprint before accepting or storing it. A GitHub App or organization-approved machine user may replace a deploy key when centralized credential rotation is required.

## 3. Create production configuration

```bash
cd /opt/vtsa-csms
sudo cp .env.production.example .env.production
sudo chmod 600 .env.production
sudo nano .env.production
```

Replace every `CHANGE_ME` and `example.com` value. Generate independent secrets on the VPS; do not paste these command outputs into tickets, chat, source control, or screenshots:

```bash
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32 | tr -d '\n')"
printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 32)"
printf 'REDIS_PASSWORD=%s\n' "$(openssl rand -hex 32)"
printf 'MINIO_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 32)"
printf 'GATEWAY_INTERNAL_API_TOKEN=%s\n' "$(openssl rand -hex 32)"
```

Configure a real SMTP service before enabling registration or password-reset flows. Keep real payment and settlement features disabled until the provider, webhook, reconciliation, and finance controls are approved.

An empty `OCPP_CHARGER_REGISTRY_JSON={}` intentionally rejects all charger connections. To enroll a simulator or charger, inject an Assets-owned registration containing real tenant/charger ULIDs and an Argon2 password hash or approved certificate fingerprint. Do not commit that registry. Keep `FEATURE_OCPP` and `FEATURE_REMOTE_CHARGING` disabled until enrollment and end-to-end acceptance testing are complete.

## 4. First deployment

Run the repository deployment command:

```bash
cd /opt/vtsa-csms
sudo bash scripts/deploy-hostinger.sh
```

The command validates configuration, builds images, starts dependencies, creates a timestamped PostgreSQL backup, runs migrations and permission synchronization, starts services, optimizes Laravel, and verifies `/health/ready`. It exits non-zero and prints relevant logs if readiness fails.

Verify the public endpoints:

```bash
curl --fail --silent https://csms.your-domain.example/health/live
curl --fail --silent https://csms.your-domain.example/health/ready
curl --fail --silent https://ocpp.your-domain.example/health/live
```

The OCPP charger URL is:

```text
wss://ocpp.your-domain.example/ocpp/{charge_point_identity}
```

Use the protocol subprotocol `ocpp1.6` or `ocpp2.0.1` and the enrolled credential. Never enable the unauthenticated development mode on the VPS.

## 5. Deploy an update

Review release notes and migrations first, then run:

```bash
cd /opt/vtsa-csms && sudo git pull --ff-only origin main && sudo bash scripts/deploy-hostinger.sh
```

The script creates a database backup before each migration. Copy `backups/` and MinIO/PostgreSQL backups to encrypted off-host storage and test restoration on a schedule. A same-server backup does not protect against VPS loss.

## Operations and rollback

```bash
cd /opt/vtsa-csms
sudo docker compose --env-file .env.production -f infra/compose.production.yaml ps
sudo docker compose --env-file .env.production -f infra/compose.production.yaml logs -f --tail=200 platform web worker ocpp-gateway caddy
sudo docker compose --env-file .env.production -f infra/compose.production.yaml restart worker scheduler
```

Application images can be rolled back by checking out the previously reviewed commit and rebuilding. Database rollback is not automatic: migrations are forward-first. For an incompatible migration, stop writes, follow the release-specific roll-forward/restore procedure, and restore both database and object storage to a consistent point.

## Production decisions still required

- VPS sizing, monitoring/alerting destination, log shipping, and uptime targets.
- Off-host encrypted backup target, retention, and tested recovery objectives.
- SMTP provider, sender-domain authentication, and email retention.
- Production OCPP charger enrollment/certificate rotation.
- Payment provider, webhook ingress controls, settlement, and accounting ownership.
- High availability, managed database/object storage, and disaster-recovery topology.
