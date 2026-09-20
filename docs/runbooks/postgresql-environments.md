# PostgreSQL production and staging environments

This database-only deployment creates one PostgreSQL/PostGIS server with two
databases:

| Environment | Database | Login |
| --- | --- | --- |
| Production | `vtsa_production` | `vtsa_app` |
| Staging | `vtsa_staging` | `vtsa_app` |

The shared login is intentional for this deployment phase. It is simpler to
operate, but it does not provide credential-level isolation: compromise of the
login grants access to both databases. Use distinct roles and credentials before
staging is exposed to untrusted users or production compliance requires strict
environment separation.

## Local production and staging

Docker Desktop must be running. Generate local-only credentials and start both
web and Flutter Web environments from PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File tools/bootstrap-environments.ps1
powershell -ExecutionPolicy Bypass -File scripts/local-environments-up.ps1 -Seed
```

The `-Seed` switch is optional. It loads the deterministic demo dataset into both
databases. The generated `.env.local-environments` file is ignored by Git.

| Surface | Production | Staging |
| --- | --- | --- |
| Laravel web/API | <http://localhost:8000> | <http://localhost:8001> |
| Flutter Web | <http://localhost:3000> | <http://localhost:3001> |
| Email verification (shared Mailpit inbox) | <http://localhost:8025> | <http://localhost:8025> |
| Database administration (shared Adminer) | <http://localhost:8081> | <http://localhost:8081> |
| Redis | `127.0.0.1:6379` | `127.0.0.1:6380` |
| PostgreSQL database | `vtsa_production` | `vtsa_staging` |

### Adminer login

Adminer is a local-only development tool. It is bound to `127.0.0.1`, starts
under the `local-tools` Compose profile, and must not be exposed through the
load balancer or a public VPS firewall rule.

Open <http://localhost:8081> and use:

| Field | Value |
| --- | --- |
| System | `PostgreSQL` |
| Server | `postgres` |
| Username | `vtsa_app` |
| Password | The `POSTGRES_APP_PASSWORD` value in `.env.local-environments` |
| Database | `vtsa_staging` or `vtsa_production` |

To start only PostgreSQL and Adminer without rebuilding the applications:

```powershell
docker compose --env-file .env.local-environments `
  -f infra/database/compose.yaml --profile local-tools `
  up --detach --wait postgres adminer
```

Verify the database identity and PostGIS from PowerShell:

```powershell
docker compose --env-file .env.local-environments `
  -f infra/database/compose.yaml exec -T postgres sh -ec `
  'PGPASSWORD="$VTSA_APP_PASSWORD" psql -h 127.0.0.1 -U vtsa_app -d vtsa_production -Atc "SELECT current_database(), current_user, postgis_version();"'

docker compose --env-file .env.local-environments `
  -f infra/database/compose.yaml exec -T postgres sh -ec `
  'PGPASSWORD="$VTSA_APP_PASSWORD" psql -h 127.0.0.1 -U vtsa_app -d vtsa_staging -Atc "SELECT current_database(), current_user, postgis_version();"'
```

Stop the application environments without deleting data:

```powershell
docker compose --env-file .env.local-environments `
  -f infra/local/compose.environments.yaml down
docker compose --env-file .env.local-environments `
  -f infra/database/compose.yaml down
```

Adding `--volumes` permanently deletes the corresponding local PostgreSQL or
Redis data and must be used only when a clean reset is intended.

## Hostinger database server

These steps assume the repository is checked out at `/opt/vtsa-csms` on the
database server. If Kodee uses another directory, provide it through
`VTSA_REPOSITORY_PATH` when running the script.

> **Existing-server warning:** this is a separate Compose project and volume.
> Do not start it beside `infra/cluster/compose.data.yaml` when that stack already
> owns port `5432`. The new volume does not automatically import existing data.
> If the earlier stack contains data, take verified dumps of both databases and
> schedule a restore/migration before switching. Never remove the earlier volume
> merely to resolve a port conflict.

Install Docker Engine and the Compose plugin, then create the protected
configuration file:

```bash
sudo install -d -m 0700 /etc/vtsa-csms
sudo cp /opt/vtsa-csms/.env.database.example /etc/vtsa-csms/database.env
sudo chmod 600 /etc/vtsa-csms/database.env
sudo nano /etc/vtsa-csms/database.env
```

Use the WireGuard address documented for the data server:

```dotenv
DATABASE_COMPOSE_PROJECT_NAME=vtsa-csms-database
POSTGRES_BIND_ADDRESS=10.77.0.1
POSTGRES_PORT=5432
POSTGRES_ADMIN_PASSWORD=GENERATE_A_LONG_RANDOM_VALUE
POSTGRES_APP_PASSWORD=GENERATE_A_DIFFERENT_LONG_RANDOM_VALUE
POSTGRES_APP_USER=vtsa_app
POSTGRES_PRODUCTION_DATABASE=vtsa_production
POSTGRES_STAGING_DATABASE=vtsa_staging
POSTGRES_DOCKER_NETWORK=vtsa-database
```

Generate values on the server without copying credentials into source control:

```bash
openssl rand -base64 48
openssl rand -base64 48
```

Install and run the idempotent database command:

```bash
sudo install -m 0755 /opt/vtsa-csms/scripts/database-up.sh \
  /usr/local/sbin/vtsa-database-up

sudo env VTSA_REPOSITORY_PATH=/opt/vtsa-csms \
  VTSA_DATABASE_ENV_FILE=/etc/vtsa-csms/database.env \
  /usr/local/sbin/vtsa-database-up
```

The command refuses wildcard binding, starts PostgreSQL/PostGIS, creates or
updates `vtsa_app`, provisions both databases, enables PostGIS, and verifies a
login to each database. Re-running it is safe and reapplies the configured
`vtsa_app` password.

Configure Kodee's application environments with the same app password without
placing it in Git:

```dotenv
# Production application
DB_HOST=10.77.0.1
DB_PORT=5432
DB_DATABASE=vtsa_production
DB_USERNAME=vtsa_app
DB_PASSWORD=the POSTGRES_APP_PASSWORD value
DB_SSLMODE=disable

# Staging application
DB_HOST=10.77.0.1
DB_PORT=5432
DB_DATABASE=vtsa_staging
DB_USERNAME=vtsa_app
DB_PASSWORD=the same POSTGRES_APP_PASSWORD value
DB_SSLMODE=disable
```

`DB_SSLMODE=disable` is acceptable only across the verified WireGuard tunnel.
Do not publish PostgreSQL port `5432` on the data server's public address. Allow
it only from the two app-server WireGuard addresses.

## Backup before migrations

Create separate dumps before either environment runs a migration:

```bash
backup_dir=/var/backups/vtsa-csms/$(date -u +%Y%m%dT%H%M%SZ)
sudo install -d -m 0700 "$backup_dir"

for database in vtsa_production vtsa_staging; do
  sudo docker compose --env-file /etc/vtsa-csms/database.env \
    -f /opt/vtsa-csms/infra/database/compose.yaml \
    exec -T postgres pg_dump -U vtsa_admin -Fc "$database" \
    | sudo tee "$backup_dir/$database.dump" >/dev/null
done

sudo ls -lh "$backup_dir"
```

Copy backups off the database VPS and periodically test restoration. A backup
that has never been restored is not a verified recovery path.
