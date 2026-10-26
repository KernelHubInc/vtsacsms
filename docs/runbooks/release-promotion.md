# Release promotion from `test_env` to production

The production load balancer serves App01 and App02. Staging runs on App02.
Only App01 runs production queue workers and the scheduler. The release command
preserves that division; it does not start jobs on App02. App02's staging
profile is web-only too; staging queue work needs a separate worker on App01
or another authorized host.

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

## Normal release

1. Push to `test_env`. CI tests it and, if successful, publishes immutable
   `sha-<40-character-commit>` images. Check that the **CI** and **Publish
   release images** GitHub Actions runs passed.
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
