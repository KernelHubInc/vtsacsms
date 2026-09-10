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

Run on App Server 1, App Server 2, and the load balancer. Use a distinct comment on each server. Never paste a repository private key into a terminal or GitHub:

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

Create separate PostgreSQL users and databases. Use PostgreSQL's interactive password command so passwords do not enter shell history:

```bash
sudo -u postgres psql
```

Inside `psql`:

```sql
CREATE ROLE vtsa_staging LOGIN;
\password vtsa_staging
CREATE DATABASE vtsa_staging OWNER vtsa_staging;

CREATE ROLE vtsa_production LOGIN;
\password vtsa_production
CREATE DATABASE vtsa_production OWNER vtsa_production;

\connect vtsa_staging
CREATE EXTENSION IF NOT EXISTS postgis;

\connect vtsa_production
CREATE EXTENSION IF NOT EXISTS postgis;

\quit
```

Run separate production and staging Redis instances with separate passwords. The templates expect production on `6379` and staging on `6380`. Create separate MinIO credentials and buckets named `vtsa-production` and `vtsa-staging`. Never copy production data to staging unless it has been explicitly sanitized.

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
