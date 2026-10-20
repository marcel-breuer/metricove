# Releases

Metricove is pre-1.0. Use `v0.MINOR.PATCH` Git tags and matching GitHub Releases. Before 1.0.0, increment MINOR for new features and breaking changes; increment PATCH for compatible bug fixes and documentation-only changes. Use `1.0.0` when the public API and installation behavior are stable. Do not reuse a release version.

## Prepare a release

1. Merge the issue-linked changes into `main` and wait for **Backend quality**, **Frontend quality**, **Security audit**, **PostgreSQL integration**, and **Validate Compose and build images** to pass on the resulting main commit.
2. Move the shipped entries from `Unreleased` in `CHANGELOG.md` into a dated heading such as `## [0.1.0] - YYYY-MM-DD`. Keep a fresh `Unreleased` section at the top.
3. Create the matching Git tag with a leading `v` (for example, `v0.1.0`) at that tested main commit and publish a GitHub Release. Release notes should summarize the user-facing changes and link to the changelog.
4. The release workflow verifies that the tag is on `main`, the exact commit has successful main-branch quality and Compose runs, the version appears in the changelog, and no image tag for that version points at another commit. It then builds and publishes API, web and gateway images for `linux/amd64` and `linux/arm64`.
5. The workflow records each published `ghcr.io/marcel-breuer/metricove/<image>:<version>@sha256:...` reference in the GitHub Release. Pin those references with `API_IMAGE_TAG`, `WEB_IMAGE_TAG` and `GATEWAY_IMAGE_TAG` in `.env` when a deployment needs content-addressed image references; otherwise set the common `APP_IMAGE_TAG` to the release version without `v`. The workflow never publishes `latest` and refuses to replace a version tag.

If a publish run is interrupted after some images were pushed, it can be rerun for the same release. Existing images are accepted only when both platform manifests carry the same release version and source commit; missing images are then built and published. A tag tied to any other commit fails closed and must not be overwritten.

## Upgrade and rollback

Before an upgrade, back up the PostgreSQL database and the `app_storage` volume. Keep the current `APP_IMAGE_TAG` so it can be restored if needed.

```sh
docker compose pull
docker compose up -d --wait
docker compose ps
curl --fail http://localhost:8080/health
curl --fail http://localhost:8080/api/health
```

To roll back the application, restore the previous release version in `.env`, then run `docker compose pull` and `docker compose up -d --wait`. Check both health endpoints and confirm household data is available. If a migration or application change is not backward-compatible, restore the matching pre-upgrade PostgreSQL and `app_storage` backups before restarting the previous images. Do not run `docker compose down --volumes` during an upgrade or rollback; that deletes persistent data.

The Compose CI workflow exercises an upgrade from the pull request's base commit to its candidate images, creates a first household through the setup API, rolls back to the base images, and confirms the setup record survives both transitions. The test runs in a separate project with disposable volumes and removes them afterward.

See the backup and restore runbook in docs/operations/backup-restore.md for executable backup commands and an isolated recovery drill.
