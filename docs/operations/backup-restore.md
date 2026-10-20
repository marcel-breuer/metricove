# Backup and restore

Metricove stores household data in the PostgreSQL database and uploaded files in the persistent app_storage volume. Back up and restore both as one recovery point.

## Create a backup

Start the installation with its normal Compose configuration, then run this from the repository root:

~~~sh
scripts/backup.sh
~~~

The script verifies the Compose configuration, then stops the API, queue worker, and scheduler services that were running so no application writes occur during the snapshot. It leaves PostgreSQL running, writes a custom-format database dump and an app_storage archive, adds a manifest and SHA-256 checksums, and restarts the services it stopped.

By default, the bundle is written under backups/. Set BACKUP_DIR to use another directory. The destination and bundle directories use mode 700; bundle files use mode 600. Backups contain private household data and must stay out of Git, image build contexts, public artifacts, and logs. Store copies under the installation's private backup and retention policy.

Bundles are not encrypted or signed. SHA-256 checksums detect accidental corruption; they do not authenticate who created a bundle. Protect backups at rest and in transit with the installation's encryption controls, and only restore bundles from a trusted source.

Each bundle contains:

- database.dump — PostgreSQL custom-format dump.
- app-storage.tar.gz — all files in the Compose app_storage volume.
- manifest.json — format version and creation time.
- SHA256SUMS — checksums for the three files above.

## Restore a backup

Before replacing an installation, create and retain a fresh backup of its current state. Select the target Compose project and environment file carefully. The restore operation replaces the configured PostgreSQL database and all files in app_storage:

~~~sh
scripts/restore-backup.sh backups/metricove-backup-<timestamp>-<pid> --apply
~~~

The script requires --apply, checks the bundle checksums and PostgreSQL archive, and rejects unsafe storage archive paths and links. It stops the API, worker, and scheduler while restoring. PostgreSQL is first restored into a temporary database and then switched into place; storage is replaced from the matching archive. The script restarts the services that were running before restore only after both parts succeed. If recovery stops after the database switch, the API, worker, and scheduler remain stopped to avoid serving mismatched database and file data.

Use application images compatible with the restored database schema. For a rollback across a non-backward-compatible migration, restore a database and app_storage bundle made before that upgrade, then run the matching earlier image.

## Exercise recovery

Run the Compose smoke test from the repository root:

~~~sh
scripts/smoke-compose-backup-restore.sh
~~~

By default, it builds the local application images, starts a uniquely named disposable Compose project, creates test-only database and private-storage markers, backs them up, mutates and deletes them, restores the bundle, and verifies both markers plus the initial setup record. It removes only that smoke project's containers and volumes when it exits.

When candidate images have already been built, the CI workflow uses:

~~~sh
APP_IMAGE_TAG=candidate BUILD_IMAGES=0 scripts/smoke-compose-backup-restore.sh
~~~

The smoke test uses synthetic data and does not access an installation's normal Compose project or backup directory.

For failure diagnosis, set `KEEP_SMOKE_STACK_ON_FAILURE=1`; the script prints the generated Compose project name and temporary artifact directory and leaves them in place. Remove that uniquely named project and temporary directory after inspection.
