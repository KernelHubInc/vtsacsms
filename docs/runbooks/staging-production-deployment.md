# Staging and production deployment on two application servers

This runbook installs independent staging and production stacks on both application servers. A successful `main` CI run builds one immutable image set, deploys it to staging first, then offers the identical commit to the approval-gated production environment. Each environment is deployed one node at a time while Nginx removes that node from its environment-specific pool.

## Deployment topology

| Concern | Production | Staging |
| --- | --- | --- |
| Docker project | `vtsa-csms-production` | `vtsa-csms-staging` |
| App-node HTTP port | `80` | `8081` |
| App-node OCPP port | `9000` | `9001` |
| PostgreSQL database | `vtsa_production` | `vtsa_staging` |
| Redis service | production instance on `6379` | separate staging instance on `6380` |
| MinIO bucket | `vtsa-production` | `vtsa-staging` |
| App environment file | `/etc/vtsa-csms/production.env` | `/etc/vtsa-csms/staging.env` |

Both nodes in one environment must have identical configuration and secrets. Staging and production must have different application keys, database credentials, Redis credentials, storage credentials, gateway tokens, and public domains.

## 1. Prepare DNS and firewall rules

Create production and staging DNS records pointing at the load balancer. Use HTTPS for both. Do not enable production deployment against a raw IP address or before TLS is valid.

Permit only these paths:

- Internet to load balancer: `80` and `443`;
- load balancer to app nodes: `80`, `8081`, `9000`, and `9001`;
- load balancer to app nodes: SSH `22`;
- app nodes to PostgreSQL, the separate Redis ports, and MinIO over a private network or TLS;
- app-node SSH should not be generally Internet-accessible.

## 2. Generate repository keys directly on the servers

Run on App Server 1, App Server 2, the load balancer, and the data server. Use a distinct comment and a distinct deploy key on each server. Never paste a repository private key into a terminal or GitHub:

```bash
sudo install -d -m 0700 /root/.ssh

if sudo test -f /root/.ssh/vtsa_repository_read; then
    sudo mv /root/.ssh/vtsa_repository_read /root/.ssh/vtsa_repository_read.invalid
fi
if sudo test -f /root/.ssh/vtsa_repository_read.pub; then
    sudo mv /root/.ssh/vtsa_repository_read.pub /root/.ssh/vtsa_repository_read.pub.invalid
fi

sudo ssh-keygen -t ed25519 -N '' \
  -f /root/.ssh/vtsa_repository_read \
  -C vtsa-CHANGE_ME_SERVER_NAME-repository-read

sudo chmod 600 /root/.ssh/vtsa_repository_read
sudo ssh-keygen -y -f /root/.ssh/vtsa_repository_read >/dev/null
sudo cat /root/.ssh/vtsa_repository_read.pub
```

Add only the displayed `.pub` line to repository **Settings → Deploy keys**. Give every server a distinct title and leave write access disabled. Continue on that server:

```bash
sudo ssh-keyscan -t ed25519 github.com | sudo tee -a /root/.ssh/known_hosts >/dev/null
sudo env GIT_SSH_COMMAND='ssh -i /root/.ssh/vtsa_repository_read -o IdentitiesOnly=yes' \
  git ls-remote git@github.com:KernelHubInc/vtsacsms.git HEAD
```

The command must return a commit hash. It must not report `error in libcrypto` or `Permission denied (publickey)`. Clone only after validation:

```bash
sudo rmdir /opt/vtsa-csms 2>/dev/null || true
sudo env GIT_SSH_COMMAND='ssh -i /root/.ssh/vtsa_repository_read -o IdentitiesOnly=yes' \
  git clone git@github.com:KernelHubInc/vtsacsms.git /opt/vtsa-csms
sudo shred -u /root/.ssh/vtsa_repository_read.invalid 2>/dev/null || true
sudo shred -u /root/.ssh/vtsa_repository_read.pub.invalid 2>/dev/null || true
```

Remove the obsolete public key from GitHub's deploy-key list after the replacement key works.

## 3. Create the GitHub Actions server-access key

On one trusted administration computer:

```bash
ssh-keygen -t ed25519 -f vtsa_actions_deploy -C vtsa-actions-deploy
```

Keep `vtsa_actions_deploy` private. Install only `vtsa_actions_deploy.pub` for the `deploy` user on both app nodes:

```bash
sudo adduser --disabled-password --gecos '' deploy || true
sudo install -d -m 0700 -o deploy -g deploy /home/deploy/.ssh
sudo nano /home/deploy/.ssh/authorized_keys
```

Use this format:

```text
restrict ssh-ed25519 REPLACE_WITH_ACTIONS_PUBLIC_KEY vtsa-actions-deploy
```

Then:

```bash
sudo chown deploy:deploy /home/deploy/.ssh/authorized_keys
sudo chmod 600 /home/deploy/.ssh/authorized_keys
```

Create the same user on the load balancer, but limit SSH forwarding to the two app-server addresses:

```text
permitopen="APP_SERVER_1_IP:22",permitopen="APP_SERVER_2_IP:22",no-agent-forwarding,no-X11-forwarding,no-pty ssh-ed25519 REPLACE_WITH_ACTIONS_PUBLIC_KEY vtsa-actions-deploy
```

## 4. Prepare both application servers

If either server already has the repository, update it without discarding local work. `git status --short` must be empty before continuing:

```bash
sudo git -C /opt/vtsa-csms status --short
sudo env GIT_SSH_COMMAND='ssh -i /root/.ssh/vtsa_repository_read -o IdentitiesOnly=yes' \
  git -C /opt/vtsa-csms fetch origin main
sudo git -C /opt/vtsa-csms switch main
sudo git -C /opt/vtsa-csms merge --ff-only origin/main
sudo test -f /opt/vtsa-csms/.env.app.staging.example
sudo test -f /opt/vtsa-csms/.env.app.production.example
```

Run on both app servers:

```bash
sudo apt update
sudo apt install -y git curl ca-certificates util-linux
docker version
docker compose version

sudo install -m 0755 /opt/vtsa-csms/scripts/deploy-app-node.sh \
  /usr/local/sbin/vtsa-deploy-app
sudo install -d -m 0700 \
  /etc/vtsa-csms \
  /var/lib/vtsa-csms/staging \
  /var/lib/vtsa-csms/production \
  /var/backups/vtsa-csms/staging \
  /var/backups/vtsa-csms/production

sudo cp /opt/vtsa-csms/.env.app.staging.example /etc/vtsa-csms/staging.env
sudo cp /opt/vtsa-csms/.env.app.production.example /etc/vtsa-csms/production.env
sudo chmod 600 /etc/vtsa-csms/staging.env /etc/vtsa-csms/production.env
sudo nano /etc/vtsa-csms/staging.env
sudo nano /etc/vtsa-csms/production.env
```

Generate two different Laravel application keys once. Copy the staging key to both staging files and the production key to both production files:

```bash
openssl rand -base64 32
openssl rand -base64 32
```

Set `APP_KEY=base64:GENERATED_VALUE`. Do not generate a different key on the second node in the same environment.

On App Server 1, keep this in both files:

```dotenv
COMPOSE_PROFILES=scheduler
```

On App Server 2, use this in both files:

```dotenv
COMPOSE_PROFILES=
```

No `CHANGE_ME` value may remain. Permit only the root-owned deployment entry point:

```bash
echo 'deploy ALL=(root) NOPASSWD: /usr/local/sbin/vtsa-deploy-app *' \
  | sudo tee /etc/sudoers.d/vtsa-deploy
sudo chmod 440 /etc/sudoers.d/vtsa-deploy
sudo visudo -cf /etc/sudoers.d/vtsa-deploy
```

Authenticate each app server to GHCR with a machine-user token limited to `read:packages`:

```bash
read -rsp 'GHCR read token: ' CR_PAT
printf '%s' "$CR_PAT" | sudo docker login ghcr.io \
  --username GITHUB_MACHINE_USER --password-stdin
unset CR_PAT
```

## 5. Prepare the data server

The fourth VPS owns PostgreSQL/PostGIS, two isolated Redis instances, and two isolated MinIO instances. The checked-in stack creates the databases, enables PostGIS, creates private buckets, and gives each application environment a bucket-limited storage user.

### 5.1 Choose and protect the data network

Use an IP address on an **encrypted private/VPN interface** shared with both app servers. Do not bind these services to `0.0.0.0` or expose them to the public Internet. A provider-private network must not be assumed to be encrypted; confirm that property with the provider or use a VPN such as WireGuard.

Record these values before continuing:

```text
DATA_SERVER_ENCRYPTED_IP=the data server's private/VPN address
APP_SERVER_1_ENCRYPTED_IP=App Server 1's private/VPN address
APP_SERVER_2_ENCRYPTED_IP=App Server 2's private/VPN address
```

At the Hostinger firewall, allow only:

| Port | Source | Purpose |
| --- | --- | --- |
| `22/tcp` | your administration IP | SSH |
| `5432/tcp` | both app-server encrypted IPs | PostgreSQL/PostGIS |
| `6379/tcp` | both app-server encrypted IPs | production Redis |
| `6380/tcp` | both app-server encrypted IPs | staging Redis |
| `9000/tcp` | both app-server encrypted IPs | production MinIO API |
| `9100/tcp` | both app-server encrypted IPs | staging MinIO API |

Do not open MinIO console ports `9001` or `9101`; the Compose file binds them to data-server localhost only. Docker-published ports can bypass ordinary UFW rules, so keep the provider firewall restrictions and the non-public bind address even if UFW is enabled.

From each app server, confirm the encrypted route is reachable before starting the stack:

```bash
ping -c 3 DATA_SERVER_ENCRYPTED_IP
```

Stop here if the encrypted/private route is not ready. Firewall allow-listing over a public network does not encrypt database, Redis, or object-storage traffic.

### 5.2 Install Docker on the data server

Connect to the data server and set its timezone to UTC:

```bash
ssh root@DATA_SERVER_PUBLIC_IP
sudo hostnamectl set-hostname vtsa-data-01
sudo timedatectl set-timezone UTC
sudo apt update
sudo apt install -y ca-certificates curl git iproute2 openssl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

. /etc/os-release
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${UBUNTU_CODENAME:-$VERSION_CODENAME} stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker version
sudo docker compose version
```

The last two commands must succeed. These commands use Docker's official Ubuntu repository rather than Ubuntu's older compatibility package.

### 5.3 Clone or update the repository

Complete Step 2 on the data server first, using a new data-server deploy key. Then clone:

```bash
sudo install -d -m 0755 /opt
sudo rmdir /opt/vtsa-csms 2>/dev/null || true
sudo env GIT_SSH_COMMAND='ssh -i /root/.ssh/vtsa_repository_read -o IdentitiesOnly=yes' \
  git clone git@github.com:KernelHubInc/vtsacsms.git /opt/vtsa-csms
sudo test -f /opt/vtsa-csms/.env.data.example
sudo test -f /opt/vtsa-csms/infra/cluster/compose.data.yaml
```

If `/opt/vtsa-csms` is already a clone, use the safe fetch and `merge --ff-only` commands from Step 4 instead. Do not use `git reset --hard` on a server with unknown local changes.

### 5.4 Create the protected data-server environment

Create the configuration and backup directories:

```bash
sudo install -d -m 0700 /etc/vtsa-csms /var/backups/vtsa-data
sudo cp /opt/vtsa-csms/.env.data.example /etc/vtsa-csms/data.env
sudo chmod 600 /etc/vtsa-csms/data.env
```

Generate independent secrets. Hex output is used so the Redis URLs on the app servers do not need percent-encoding. Save the values in a password manager; do not paste them into GitHub, chat, tickets, or source control:

```bash
for name in \
  POSTGRES_SUPERUSER_PASSWORD \
  POSTGRES_PRODUCTION_PASSWORD \
  POSTGRES_STAGING_PASSWORD \
  REDIS_PRODUCTION_PASSWORD \
  REDIS_STAGING_PASSWORD \
  MINIO_PRODUCTION_ROOT_PASSWORD \
  MINIO_PRODUCTION_APP_PASSWORD \
  MINIO_STAGING_ROOT_PASSWORD \
  MINIO_STAGING_APP_PASSWORD
do
  printf '%s=' "$name"
  openssl rand -hex 32
done
```

Edit the file:

```bash
sudo nano /etc/vtsa-csms/data.env
```

Set `DATA_BIND_ADDRESS` to the data server's encrypted/private interface IP, paste each generated value into its matching field, and use non-secret service usernames such as:

```dotenv
MINIO_PRODUCTION_ROOT_USER=vtsa-production-root
MINIO_PRODUCTION_APP_USER=vtsa-production-app
MINIO_STAGING_ROOT_USER=vtsa-staging-root
MINIO_STAGING_APP_USER=vtsa-staging-app
```

Every `CHANGE_ME` value must be removed. Check without displaying any secrets:

```bash
if sudo grep -q CHANGE_ME /etc/vtsa-csms/data.env; then
  echo 'ERROR: unresolved placeholders remain'
else
  echo 'Environment file is complete'
fi
sudo stat -c '%a %U:%G %n' /etc/vtsa-csms/data.env
```

The expected permission is `600 root:root`.

### 5.5 Start and validate all data services

Install and run the checked-in preparation command:

```bash
sudo install -m 0755 /opt/vtsa-csms/scripts/prepare-data-server.sh \
  /usr/local/sbin/vtsa-prepare-data
sudo /usr/local/sbin/vtsa-prepare-data
```

On first start this command:

1. validates the protected environment and refuses a wildcard/public bind address;
2. starts PostgreSQL 18 with PostGIS, production Redis, staging Redis, and two MinIO instances;
3. creates `vtsa_production` and `vtsa_staging` with different owners and passwords;
4. enables PostGIS in both databases;
5. creates private `vtsa-production` and `vtsa-staging` buckets;
6. creates separate bucket-limited MinIO application users;
7. verifies PostgreSQL/PostGIS and both Redis instances.

Inspect health and recent logs:

```bash
sudo docker compose --env-file /etc/vtsa-csms/data.env \
  -f /opt/vtsa-csms/infra/cluster/compose.data.yaml ps
sudo docker compose --env-file /etc/vtsa-csms/data.env \
  -f /opt/vtsa-csms/infra/cluster/compose.data.yaml logs --tail=100
```

The five long-running services must be `Up` and healthy. The two `minio-*-init` services are one-shot provisioning jobs and are not expected to remain running.

### 5.6 Configure both application servers

Use the exact same values on App Server 1 and App Server 2 for a given environment. Do not copy production secrets into staging.

Set these values in `/etc/vtsa-csms/production.env`:

```dotenv
DB_HOST=DATA_SERVER_ENCRYPTED_IP
DB_PORT=5432
DB_DATABASE=vtsa_production
DB_USERNAME=vtsa_production
DB_PASSWORD=the POSTGRES_PRODUCTION_PASSWORD value
DB_SSLMODE=disable

REDIS_HOST=DATA_SERVER_ENCRYPTED_IP
REDIS_PORT=6379
REDIS_PASSWORD=the REDIS_PRODUCTION_PASSWORD value
GATEWAY_REDIS_URL=redis://:the_REDIS_PRODUCTION_PASSWORD_value@DATA_SERVER_ENCRYPTED_IP:6379/2

MINIO_ACCESS_KEY=the MINIO_PRODUCTION_APP_USER value
MINIO_SECRET_KEY=the MINIO_PRODUCTION_APP_PASSWORD value
MINIO_BUCKET=vtsa-production
MINIO_ENDPOINT=http://DATA_SERVER_ENCRYPTED_IP:9000
```

Set these values in `/etc/vtsa-csms/staging.env`:

```dotenv
DB_HOST=DATA_SERVER_ENCRYPTED_IP
DB_PORT=5432
DB_DATABASE=vtsa_staging
DB_USERNAME=vtsa_staging
DB_PASSWORD=the POSTGRES_STAGING_PASSWORD value
DB_SSLMODE=disable

REDIS_HOST=DATA_SERVER_ENCRYPTED_IP
REDIS_PORT=6380
REDIS_PASSWORD=the REDIS_STAGING_PASSWORD value
GATEWAY_REDIS_URL=redis://:the_REDIS_STAGING_PASSWORD_value@DATA_SERVER_ENCRYPTED_IP:6380/2

MINIO_ACCESS_KEY=the MINIO_STAGING_APP_USER value
MINIO_SECRET_KEY=the MINIO_STAGING_APP_PASSWORD value
MINIO_BUCKET=vtsa-staging
MINIO_ENDPOINT=http://DATA_SERVER_ENCRYPTED_IP:9100
```

`DB_SSLMODE=disable` and the `http://` MinIO endpoints are permitted here **only because Step 5.1 requires an encrypted tunnel/interface**. If the transport is not encrypted, configure native PostgreSQL and MinIO TLS first, change the modes/URLs accordingly, and distribute the trusted CA; do not weaken transport security to make a connection work.

`MINIO_PUBLIC_URL` is separate from the internal endpoint. Set it to each environment's TLS-protected public storage hostname after the load balancer is configured to proxy that hostname to the correct MinIO API. Never expose the MinIO admin console publicly.

From each app server, verify that only the intended data ports are reachable:

```bash
DATA_HOST=DATA_SERVER_ENCRYPTED_IP
for port in 5432 6379 6380 9000 9100; do
  timeout 3 bash -c "</dev/tcp/$DATA_HOST/$port" \
    && echo "reachable: $DATA_HOST:$port" \
    || echo "blocked: $DATA_HOST:$port"
done
```

All five checks must report `reachable`. A timeout normally means the bind address, encrypted route, or Hostinger firewall rule is wrong.

### 5.7 Make a first backup before migrations

The application deployment command creates a database dump before a migration, but the data server still needs its own off-server backup policy. Create an initial PostgreSQL backup:

```bash
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="/var/backups/vtsa-data/$stamp"
sudo install -d -m 0700 "$backup_dir"

sudo docker compose --env-file /etc/vtsa-csms/data.env \
  -f /opt/vtsa-csms/infra/cluster/compose.data.yaml \
  exec -T postgres pg_dumpall -U vtsa_admin --globals-only \
  | sudo tee "$backup_dir/postgres-globals.sql" >/dev/null
sudo docker compose --env-file /etc/vtsa-csms/data.env \
  -f /opt/vtsa-csms/infra/cluster/compose.data.yaml \
  exec -T postgres pg_dump -U vtsa_admin -Fc vtsa_production \
  | sudo tee "$backup_dir/vtsa-production.dump" >/dev/null
sudo docker compose --env-file /etc/vtsa-csms/data.env \
  -f /opt/vtsa-csms/infra/cluster/compose.data.yaml \
  exec -T postgres pg_dump -U vtsa_admin -Fc vtsa_staging \
  | sudo tee "$backup_dir/vtsa-staging.dump" >/dev/null

sudo env BACKUP_SET="$stamp" docker compose \
  --env-file /etc/vtsa-csms/data.env \
  -f /opt/vtsa-csms/infra/cluster/compose.data.yaml \
  --profile backup run --rm minio-production-backup
sudo env BACKUP_SET="$stamp" docker compose \
  --env-file /etc/vtsa-csms/data.env \
  -f /opt/vtsa-csms/infra/cluster/compose.data.yaml \
  --profile backup run --rm minio-staging-backup

sudo find "$backup_dir" -type f ! -name SHA256SUMS -print0 \
  | sudo xargs -0 sha256sum \
  | sudo tee "$backup_dir/SHA256SUMS" >/dev/null
sudo ls -lh "$backup_dir"
```

Copy encrypted database backups and MinIO object backups to storage outside this VPS, define retention, and perform a restore test before enabling production. A backup kept only on the data server does not protect against server or disk loss. Redis is not the system of record and does not replace PostgreSQL or MinIO backups.

## 6. Configure both load-balancer pools

Run on the load balancer after cloning:

```bash
sudo install -m 0755 /opt/vtsa-csms/scripts/load-balancer-node.sh \
  /usr/local/sbin/vtsa-lb-node
sudo install -d -m 0700 /etc/vtsa-csms /var/lib/vtsa-csms/load-balancer
sudo install -d -m 0755 /etc/nginx/vtsa-upstreams
sudo install -m 0644 /opt/vtsa-csms/infra/cluster/nginx-proxy-headers.conf \
  /etc/nginx/vtsa-proxy-headers.conf

sudo cp /opt/vtsa-csms/.env.load-balancer.staging.example \
  /etc/vtsa-csms/load-balancer.staging.env
sudo cp /opt/vtsa-csms/.env.load-balancer.production.example \
  /etc/vtsa-csms/load-balancer.production.env
sudo chmod 600 /etc/vtsa-csms/load-balancer.*.env
sudo nano /etc/vtsa-csms/load-balancer.staging.env
sudo nano /etc/vtsa-csms/load-balancer.production.env
```

Use private app-node addresses when available. Initialize the four upstream files, replacing `APP1_IP` and `APP2_IP`:

```bash
printf 'server APP1_IP:80 max_fails=1 fail_timeout=5s;\nserver APP2_IP:80 max_fails=1 fail_timeout=5s;\n' \
  | sudo tee /etc/nginx/vtsa-upstreams/production-http.conf
printf 'server APP1_IP:8081 max_fails=1 fail_timeout=5s;\nserver APP2_IP:8081 max_fails=1 fail_timeout=5s;\n' \
  | sudo tee /etc/nginx/vtsa-upstreams/staging-http.conf
printf 'server APP1_IP:9000 max_fails=1 fail_timeout=5s;\nserver APP2_IP:9000 max_fails=1 fail_timeout=5s;\n' \
  | sudo tee /etc/nginx/vtsa-upstreams/production-ocpp.conf
printf 'server APP1_IP:9001 max_fails=1 fail_timeout=5s;\nserver APP2_IP:9001 max_fails=1 fail_timeout=5s;\n' \
  | sudo tee /etc/nginx/vtsa-upstreams/staging-ocpp.conf
```

Back up the active Nginx configuration. Integrate `infra/cluster/nginx-load-balancer.conf.example`, replace both domain placeholders, obtain valid TLS certificates, and redirect HTTP to HTTPS. OCPP requires TLS-enabled WebSocket virtual hosts before real chargers are enabled. Do not activate a duplicate default port-80 server.

```bash
sudo nginx -t
sudo systemctl reload nginx

echo 'deploy ALL=(root) NOPASSWD: /usr/local/sbin/vtsa-lb-node *' \
  | sudo tee /etc/sudoers.d/vtsa-load-balancer
sudo chmod 440 /etc/sudoers.d/vtsa-load-balancer
sudo visudo -cf /etc/sudoers.d/vtsa-load-balancer
```

## 7. Perform the first deployment manually

Wait for GitHub Actions to publish images for the current commit. On both app nodes:

```bash
sudo env GIT_SSH_COMMAND='ssh -i /root/.ssh/vtsa_repository_read -o IdentitiesOnly=yes' \
  git -C /opt/vtsa-csms fetch origin main
RELEASE_TAG="sha-$(sudo git -C /opt/vtsa-csms rev-parse origin/main)"
```

On App Server 1:

```bash
sudo /usr/local/sbin/vtsa-deploy-app staging "$RELEASE_TAG" --migrate
sudo /usr/local/sbin/vtsa-deploy-app production "$RELEASE_TAG" --migrate
```

On App Server 2:

```bash
sudo /usr/local/sbin/vtsa-deploy-app staging "$RELEASE_TAG"
sudo /usr/local/sbin/vtsa-deploy-app production "$RELEASE_TAG"
```

Verify both stacks on both nodes:

```bash
curl --fail http://127.0.0.1:8081/health/ready
curl --fail http://127.0.0.1:80/health/ready
```

On the load balancer:

```bash
sudo /usr/local/sbin/vtsa-lb-node staging status app1
sudo /usr/local/sbin/vtsa-lb-node staging status app2
sudo /usr/local/sbin/vtsa-lb-node production status app1
sudo /usr/local/sbin/vtsa-lb-node production status app2
```

## 8. Configure GitHub environments

Create GitHub environments named exactly `staging` and `production`. Require reviewers on `production`; staging should not require approval.

Convert the Actions private key to one-line Base64 on Windows PowerShell:

```powershell
$keyBytes = [System.IO.File]::ReadAllBytes("$PWD\vtsa_actions_deploy")
[Convert]::ToBase64String($keyBytes)
```

Add these secrets to both environments:

- `DEPLOY_SSH_KEY_B64`: the one-line Base64 output;
- `KNOWN_HOSTS`: verified entries for the load balancer and both app nodes.

The workflow decodes the key and runs `ssh-keygen -y` before any connection. A truncated or corrupted key fails before a server can be drained.

Add these variables to both environments:

- `DEPLOY_USER=deploy`
- `LOAD_BALANCER_HOST=187.53.134.115`
- `APP_NODE_1_HOST=187.77.130.156`, or its private address reachable through the load balancer
- `APP_NODE_2_HOST=187.53.134.122`, or its private address reachable through the load balancer
- `PUBLIC_URL`: that environment's HTTPS URL without a trailing slash

Finally add these repository-level Actions variables after manual verification:

```text
STAGING_DEPLOY_ENABLED=true
PRODUCTION_DEPLOY_ENABLED=true
```

## 9. Normal release flow

Every successful `main` CI run:

1. builds and publishes commit-addressed images once;
2. drains, migrates, deploys, and verifies both staging nodes sequentially;
3. waits for production approval;
4. promotes the identical commit through both production nodes sequentially;
5. verifies each public readiness endpoint.

The scripts reject concurrent deployments, placeholders, incorrect environment files, invalid commit tags, failed backups, failed migrations, unhealthy peers, and unhealthy replacement nodes. Migrations must remain backward-compatible with the previous running version for zero downtime.

## 10. Verification and recovery

```bash
sudo docker compose --env-file /etc/vtsa-csms/staging.env \
  -f /opt/vtsa-csms/infra/cluster/compose.app.yaml ps
sudo docker compose --env-file /etc/vtsa-csms/production.env \
  -f /opt/vtsa-csms/infra/cluster/compose.app.yaml ps
```

Manual recovery uses the same immutable release tag. Run migrations only on App Server 1:

```bash
sudo /usr/local/sbin/vtsa-deploy-app staging sha-FULL_40_CHARACTER_COMMIT --migrate
sudo /usr/local/sbin/vtsa-deploy-app production sha-FULL_40_CHARACTER_COMMIT --migrate
```

Do not roll images back across a destructive or incompatible database migration. Prefer a forward fix and preserve pre-migration backups according to the retention policy.
