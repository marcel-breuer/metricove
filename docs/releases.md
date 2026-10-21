# Releases

Metricove is pre-1.0. Use `v0.MINOR.PATCH` Git tags and matching GitHub Releases. Before 1.0.0, increment MINOR for new features and breaking changes, and PATCH for compatible bug fixes. Use `1.0.0` when the public API and installation behavior are stable. Do not reuse a release version.

## Automated releases

1. Use Conventional Commit pull request titles. `feat` and breaking changes bump MINOR before 1.0.0; `fix` bumps PATCH. Documentation, chore, CI, and test-only changes do not trigger a release by themselves.
2. After a push to `main`, the Release Please workflow waits for successful **Quality gates** and **Docker Compose build** runs for that exact commit. It opens or updates one release pull request with the version, manifest, and dated changelog section.
3. Review and merge the release pull request after its checks pass. Release Please then creates the matching stable `vMAJOR.MINOR.PATCH` Git tag and publishes a GitHub Release. This published release starts the image workflow; no manual tag or release creation is needed.
4. The image workflow verifies the tag, changelog heading, `main` ancestry, and successful CI for that exact commit. It then builds and publishes API, web and gateway images for `linux/amd64` and `linux/arm64`.
5. The workflow records each published `ghcr.io/marcel-breuer/metricove/<image>:<version>@sha256:...` reference in the GitHub Release. Pin those references with `API_IMAGE_TAG`, `WEB_IMAGE_TAG` and `GATEWAY_IMAGE_TAG` in `.env` when a deployment needs content-addressed image references; otherwise set the common `APP_IMAGE_TAG` to the release version without `v`. The workflow never publishes `latest` and refuses to replace a version tag.

## GitHub Actions setup

Add a fine-grained GitHub token with access to this repository as the repository Actions secret `RELEASE_PLEASE_TOKEN`. Grant **Contents: read and write** and **Pull requests: read and write**. Release Please uses this token to open its reviewable pull request and publish the release. The built-in `GITHUB_TOKEN` cannot be used here because GitHub does not start follow-up pull request or release workflows for events it creates. If the first main-branch run failed because the secret was missing, add the secret and run **Release Please** from the Actions tab on `main`.

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
