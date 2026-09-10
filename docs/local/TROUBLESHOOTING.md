# Local demo troubleshooting

| Symptom | Check and resolution |
|---|---|
| Docker command fails | Start Docker Desktop, confirm `docker info`, allocate 8 GB+, then rerun `make demo-up`. |
| Ports 3000/5432/6379/8000/8025/9000/9001 are busy | Stop the conflicting local service or adjust `infra/compose.yaml`; document the changed URLs. |
| Platform remains unhealthy | Run `make demo-logs`; inspect `platform`, `postgres`, `redis`, and `minio-init`. |
| Seeder refuses | Confirm `APP_ENV=local` and `FEATURE_DEMO_MODE=true`. It intentionally refuses production. |
| Locator list works but tiles do not | Verify browser internet access to `tile.openstreetmap.org`. The accessible list/API remain functional. |
| Google map does not load | Use OSM, or verify the browser key, referrer restriction, Maps JavaScript API, quota and billing alerts. |
| Flutter login says tenant required | Use tenant `01J0000000VTSADEMA00000000` in the Dart defines. |
| Android cannot reach API | Use `10.0.2.2`, not `localhost`; physical devices need the host LAN IP. |
| Email is missing | Open Mailpit and confirm `mailpit`/`worker` are healthy. |
| Browser binaries are missing | Run `powershell -File tests/browser/run.ps1`; it installs pinned Chromium locally. |
| Seed data is inconsistent | Run `make demo-reset`; this removes only Power Solutions demo volumes and recreates deterministic data. |
| A Milestone 2 action returns 409 | Expected: enable only after implementing the related Milestone 2 integration. Do not bypass the flag. |
