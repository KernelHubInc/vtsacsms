# Release promotion from `test_env` to production

The production load balancer serves App01 and App02. Staging runs on App02.
Only App01 runs production queue workers and the scheduler. The release command
preserves that division; it does not start production jobs on App02. App02's
new staging profile is web-only. Its older staging charger and reporting
consumers are separate existing containers and must remain available until a
separate, verified worker transition is complete.

## One-time access setup

The load balancer holds the permanent Ed25519 key at
`/root/.ssh/vtsa_cluster_deploy`. Its **public** half is installed in root's
`authorized_keys` on App01 and App02 with `from="187.53.134.115",restrict`.
This permits root deployment commands only from the load balancer; protect
the private key as a root credential. Never copy it to GitHub or a developer
machine. Pin both app hosts' verified SSH host keys in
`/root/.ssh/known_hosts`. Do not disable host-key checking. App02's Hostinger
firewall must allow TCP 22 from `187.53.134.115/32` only for this path.

The load balancer needs outbound SSH to both app nodes. Its inbound SSH can
remain closed. Preserve the existing read-only GitHub deploy key on each app
node for source fetches and the GHCR read credential for image pulls. These
keys serve different purposes.

Install the checked-in `scripts/release-cluster.sh` as
`/usr/local/sbin/vtsa-release-cluster` on the load balancer. Update
`/usr/local/sbin/vtsa-deploy-app` on App01 and App02 to the checked-in version
before the first `test_env` release: staging fetches `test_env`, production
fetches `main`, and each rejects a commit outside its branch.

On the existing App02 staging host, confirm the legacy staging Docker network
with `docker network ls` and set `APP_NETWORK_NAME` to its exact name and
`APP_NETWORK_EXTERNAL=true` in the private staging environment file. This
connects the new web pair to the existing Redis and OCPP consumers. The staging
deployer deliberately does not remove orphan containers; verify the old staging
consumers and Redis are still running after the first rollout. Do not set these
network overrides in the production environment files.

Keep App02's existing `/opt/vtsa-csms` checkout untouched: it contains local
staging gateway configuration and other uncommitted operational edits. Create
the clean `/opt/vtsa-csms-release` checkout for the image deployment. The
staging deploy helper selects that checkout when present. Do not stash or reset
the legacy checkout as part of an application release.

## Normal release

1. Push to `test_env`. The CI workflow tests it and, only after every check
   passes, publishes immutable `sha-<40-character-commit>` images. Check that
   the **CI** run, including all **Publish release image** jobs, passed.
2. On the load balancer console, run:

   ```bash
   sudo vtsa-release-cluster staging latest --check
   sudo vtsa-release-cluster staging latest
   ```

   Verify the staging login, wallet, charging, and reporting paths relevant to
   the change. The script checks `/health/ready` but cannot replace those
   functional checks.
3. Merge the tested changes into `main`. A normal merge commit can have a new
   SHA; promotion compares the source trees and refuses a main branch that
   differs from the staged source. If the merge adds other changes, test and
   deploy those changes to staging before production.
4. After CI and image publishing pass on `main`, run:

   ```bash
   sudo vtsa-release-cluster production latest --check
   sudo vtsa-release-cluster production latest
   ```

Use an explicit `sha-FULL_40_CHARACTER_COMMIT` instead of `latest` when you
need to pin a release despite newer branch commits. The production script
verifies the staged source tree, both production nodes, and the
public endpoint. It deploys and migrates App01 once, verifies it, then deploys
App02 without jobs, and verifies both nodes again. App01 remains the sole
production worker/scheduler host. The load balancer's existing passive
failover handles a node restart; this script does not mutate Nginx upstreams.

The primary worker listens to `charging`, `default`, and `reports`, in that
priority order. Its 120-second timeout is shorter than Redis's 180-second
retry interval so an unfinished job is not reserved twice before the first
worker is terminated. Reports and ordinary jobs share this worker initially;
monitor queue latency before enabling live charging at scale.

Do not run a second release concurrently. The load balancer script uses a
shared `flock` lock. If a step fails, stop and inspect the node; the app-node
script attempts to restore its previous image on health failure. Database
migrations are not automatically rolled back, so schema changes must remain
compatible with the previously running image. Each migration run creates a
pre-migration database dump on App01 or App02 as applicable; keep independent
off-server database backups as well.

## Current limits

- A branch push publishes images but **does not automatically deploy**. This
  keeps production promotion deliberate and allows staged functional testing.
- The load balancer needs permanent SSH access **outbound** to app nodes and
  host keys must be pinned before the script can run.
- `/health/ready` currently proves the web app and PostgreSQL readiness for
  the deployed image. Check Redis/queue separately until the Redis-aware
  readiness image is released. Production email is still log-only; this is not
  a full business-launch sign-off.
- The current staging environment is App02 only. Do not use the older
  two-staging-node workflow or drain commands for this topology.
