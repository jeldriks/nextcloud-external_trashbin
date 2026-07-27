# Final hardcoding and portability audit

Release: **2.4.0**  
Target: **Nextcloud 34**

## Runtime values not hardcoded

The application contains no deployment-specific runtime values for:

- Nextcloud hostname or public URL
- IP address, SMB server, share, domain, username, or password
- Nextcloud user ID such as `admin`
- External mount names such as `Cloud` or `Files`
- Database engine, database name, or table prefix such as `oc_`
- Docker container name, Unix account, app path, data path, or `occ` path

The database table is referenced as `external_trash_items`; Nextcloud's database layer applies the configured table prefix automatically.

## Intentional constants

These values are application or Nextcloud protocol identifiers, not deployment assumptions:

- App ID: `external_trashbin`
- External recycle directory: `.recycle`
- Nextcloud mount types: `external` and `external-session`
- DAV deleted-file suffix: `.d<timestamp>`
- Nextcloud compatibility: major version 34
- Background retention interval: 30 minutes

## Hardening changes in 2.4.0

- The storage wrapper is now attached only to external and external-session mounts. It no longer changes `fopen`, `unlink`, or `rmdir` behavior on primary/internal storage.
- Supported mount-type strings are centralized in one class.
- Deletion time uses Nextcloud's `ITimeFactory` rather than PHP's global `time()`.
- User-relative display paths are derived from `IRootFolder::getUserFolder()->getPath()` rather than assuming `/<uid>/files/`.
- SMB-specific comments were generalized because the runtime code uses `IStorage` and can operate on any writable external backend that supports the required storage operations.

## Deliberate compatibility boundary

The custom trash backend API is under `OCA\\Files_Trashbin`, not the stable public `OCP` namespace. The package therefore remains restricted to Nextcloud 34 and must be reviewed before enabling it on another major release.
