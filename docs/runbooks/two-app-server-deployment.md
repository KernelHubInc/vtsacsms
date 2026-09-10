# Two application server deployment

This runbook installs the same immutable GitHub release on two application servers behind an existing load balancer. GitHub deploys App Server 1, waits for readiness, and then deploys App Server 2. PostgreSQL, Redis, and MinIO are external shared services and are not started on either app server.

## Required load-balancer behavior

Both backends must point to port `80`. The deployment workflow uses a root-owned Nginx include to mark one backend `down`, reloads Nginx gracefully, deploys and verifies that node, and then re-enables it. It never drains a node unless the other node passes `GET /health/ready`.

The repository provides the required Nginx configuration:

```text
infra/cluster/nginx-load-balancer.conf.example
```

Only the load balancer should reach application ports `80` and `9000`. Database, Redis, and MinIO must allow only the two application-server addresses over an encrypted private link or their own TLS configuration.

## 1. Create the GitHub Actions SSH key

On a trusted administration computer, create the key that GitHub Actions will use to reach the servers:

```bash
ssh-keygen -t ed25519 -f vtsa_actions_deploy -C vtsa-actions-deploy
```

Keep the private key out of Git and chat. Each server creates its own read-only repository deploy key in the next step, so repository private keys never need to be copied or pasted.

## 2. Prepare each application server

Run on both App Server 1 and App Server 2:

```bash
sudo apt update
sudo apt install -y git curl ca-certificates util-linux
docker version
docker compose version

sudo adduser --disabled-password --gecos "" deploy
sudo install -d -m 0700 -o deploy -g deploy /home/deploy/.ssh
```

Append the contents of `vtsa_actions_deploy.pub` to `/home/deploy/.ssh/authorized_keys`, prefixed with `restrict`:

```text
restrict ssh-ed25519 REPLACE_WITH_PUBLIC_KEY vtsa-actions-deploy
```

Generate the repository key directly on the server. Use a distinct comment such as `vtsa-app1-repository-read` or `vtsa-app2-repository-read` on each node:

```bash
sudo chown deploy:deploy /home/deploy/.ssh/authorized_keys
sudo chmod 600 /home/deploy/.ssh/authorized_keys

sudo install -d -m 0700 /root/.ssh
sudo ssh-keygen -t ed25519 -N '' -f /root/.ssh/vtsa_repository_read -C vtsa-app1-repository-read
sudo chmod 600 /root/.ssh/vtsa_repository_read
sudo cat /root/.ssh/vtsa_repository_read.pub
```

Add only the displayed `.pub` value to GitHub repository **Settings → Deploy keys** without write access. Give each server key a distinct title. Then continue:

```bash
sudo ssh-keyscan -t ed25519 github.com | sudo tee -a /root/.ssh/known_hosts >/dev/null
sudo tee /root/.ssh/config >/dev/null <<'EOF'
Host github-vtsa
    HostName github.com
    User git
    IdentityFile /root/.ssh/vtsa_repository_read
    IdentitiesOnly yes
EOF
sudo chmod 600 /root/.ssh/config /root/.ssh/known_hosts

sudo git clone git@github-vtsa:KernelHubInc/vtsacsms.git /opt/vtsa-csms
sudo install -m 0755 /opt/vtsa-csms/scripts/deploy-app-node.sh /usr/local/sbin/vtsa-deploy-app
sudo install -d -m 0700 /etc/vtsa-csms /var/lib/vtsa-csms /var/backups/vtsa-csms
sudo cp /opt/vtsa-csms/.env.app.example /etc/vtsa-csms/app.env
sudo chmod 600 /etc/vtsa-csms/app.env
sudo nano /etc/vtsa-csms/app.env
```

Use identical `APP_KEY`, database, Redis, storage, mail, and gateway secrets on both nodes. On App Server 1 keep:

```dotenv
COMPOSE_PROFILES=scheduler
```

On App Server 2 set:

```dotenv
COMPOSE_PROFILES=
```

Do not continue while any `CHANGE_ME` or `example.com` value remains.

Allow the deployment user to run only the root-owned deployment entry point:

```bash
echo 'deploy ALL=(root) NOPASSWD: /usr/local/sbin/vtsa-deploy-app *' | sudo tee /etc/sudoers.d/vtsa-deploy
sudo chmod 440 /etc/sudoers.d/vtsa-deploy
sudo visudo -cf /etc/sudoers.d/vtsa-deploy
```

## 3. Authenticate each server to GHCR

Create a machine-user personal access token classic with `read:packages` only. Run on both app servers:

```bash
read -rsp "GHCR read token: " CR_PAT
printf '%s' "$CR_PAT" | sudo docker login ghcr.io --username GITHUB_MACHINE_USER --password-stdin
unset CR_PAT
```

## 4. Perform the first app-node deployment

Wait for the **Deploy production app nodes** workflow's `publish` job to create the three GHCR images. Keep `PRODUCTION_DEPLOY_ENABLED` unset. Obtain and deploy the full commit independently on each checkout:

```bash
cd /opt/vtsa-csms
sudo git fetch origin main
RELEASE_TAG="sha-$(sudo git rev-parse origin/main)"
echo "$RELEASE_TAG"
```

On App Server 1:

```bash
sudo /usr/local/sbin/vtsa-deploy-app "$RELEASE_TAG" --migrate
```

On App Server 2:

```bash
sudo /usr/local/sbin/vtsa-deploy-app "$RELEASE_TAG"
```

Both must return a ready response before changing the load balancer:

```bash
curl --fail http://127.0.0.1/health/ready
```

## 5. Configure the load balancer as an SSH bastion and drain controller

Create the same `deploy` user on the load balancer and install `vtsa_actions_deploy.pub` in its `authorized_keys`. Limit that key to forwarding SSH only to the two app servers:

```text
permitopen="APP_SERVER_1_IP:22",permitopen="APP_SERVER_2_IP:22",no-agent-forwarding,no-X11-forwarding,no-pty ssh-ed25519 REPLACE_WITH_PUBLIC_KEY vtsa-actions-deploy
```

Generate another dedicated root GitHub read key on the load balancer and add only its public key to the repository's read-only deploy keys. Repeat the `github-vtsa` SSH configuration from step 2. Clone the repository read-only, then install the root-owned drain controller:

```bash
sudo git clone git@github-vtsa:KernelHubInc/vtsacsms.git /opt/vtsa-csms
sudo install -m 0755 /opt/vtsa-csms/scripts/load-balancer-node.sh /usr/local/sbin/vtsa-lb-node
sudo install -d -m 0700 /etc/vtsa-csms /var/lib/vtsa-csms/load-balancer
sudo cp /opt/vtsa-csms/.env.load-balancer.example /etc/vtsa-csms/load-balancer.env
sudo chmod 600 /etc/vtsa-csms/load-balancer.env
sudo nano /etc/vtsa-csms/load-balancer.env
```

Back up the active Nginx virtual host. Replace its existing upstream and HTTP server block with `infra/cluster/nginx-load-balancer.conf.example`; do not enable it alongside a duplicate port-80 virtual host. Initialize `/etc/nginx/vtsa-upstreams/http.conf` with the same two app addresses, then validate and reload:

```bash
sudo install -d -m 0755 /etc/nginx/vtsa-upstreams
printf 'server APP_SERVER_1_IP:80 max_fails=1 fail_timeout=5s;\nserver APP_SERVER_2_IP:80 max_fails=1 fail_timeout=5s;\n' | sudo tee /etc/nginx/vtsa-upstreams/http.conf
sudo nginx -t
sudo systemctl reload nginx
```

Permit the deployment user to run only the root-owned drain controller:

```bash
echo 'deploy ALL=(root) NOPASSWD: /usr/local/sbin/vtsa-lb-node *' | sudo tee /etc/sudoers.d/vtsa-load-balancer
sudo chmod 440 /etc/sudoers.d/vtsa-load-balancer
sudo visudo -cf /etc/sudoers.d/vtsa-load-balancer
sudo /usr/local/sbin/vtsa-lb-node status app1
sudo /usr/local/sbin/vtsa-lb-node status app2
```

At the firewall, allow app-server SSH only from the load balancer. GitHub Actions reaches both nodes through `ProxyJump`; it does not need direct app-server SSH exposure.

## 6. Configure the GitHub production environment

Create repository environment `production`. Add:

Secrets:

- `PRODUCTION_DEPLOY_SSH_KEY`: contents of `vtsa_actions_deploy`.
- `PRODUCTION_KNOWN_HOSTS`: verified `ssh-keyscan -H` lines for the load balancer and both app servers.

Variables:

- `PRODUCTION_DEPLOY_USER=deploy`
- `LOAD_BALANCER_HOST`: load-balancer SSH address.
- `APP_NODE_1_HOST`: App Server 1 address as reachable from the load balancer.
- `APP_NODE_2_HOST`: App Server 2 address as reachable from the load balancer.
- `PRODUCTION_URL`: public HTTPS URL without a trailing slash.
- Repository Actions variable `PRODUCTION_DEPLOY_ENABLED=true` — add this last, after both nodes and Nginx are verified.

Verify every SSH host fingerprint through the Hostinger console before placing it in `PRODUCTION_KNOWN_HOSTS`.

## 7. Deploy

Every successful `main` CI run publishes commit-addressed images and starts the sequential deployment automatically. To trigger the first deployment after configuration, rerun the latest **Deploy production app nodes** workflow or push a reviewed commit to `main`.

Manual recovery uses the same command. Run migration only on App Server 1:

```bash
sudo /usr/local/sbin/vtsa-deploy-app sha-FULL_40_CHARACTER_GIT_COMMIT --migrate
```

Then on App Server 2:

```bash
sudo /usr/local/sbin/vtsa-deploy-app sha-FULL_40_CHARACTER_GIT_COMMIT
```

The script locks concurrent deployments, pulls the exact images, backs up PostgreSQL before migration, runs migrations once, waits for node readiness, preserves the previous image for rollback, and refuses placeholder configuration.

## Verify

```bash
curl --fail http://127.0.0.1/health/ready
sudo docker compose --env-file /etc/vtsa-csms/app.env -f /opt/vtsa-csms/infra/cluster/compose.app.yaml ps
```

From another machine, call the public `/health/ready` repeatedly during a deployment. There should be no non-2xx response. OCPP WebSockets on a replaced gateway reconnect through the load balancer; Laravel reconstructs the charging session from durable events.
