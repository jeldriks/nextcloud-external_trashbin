# Changelog

## 2.4.0

- Completed deployment-specific hardcoding audit.
- Restricted the DAV/download/delete storage wrapper to external mounts only.
- Centralized supported external mount types.
- Replaced `time()` with Nextcloud's `ITimeFactory`.
- Removed the hardcoded `/<uid>/files/` path assumption.
- Generalized runtime documentation from SMB-only wording to external storage.
- Added final audit documentation and release validation.

## 2.3.2

- Restored items through a direct same-storage rename.
- Avoided the generic cache move path that produced null metadata errors.
- Rebuilt cache state after a successful restore.

## 2.3.1

- Replaced unsupported `QueryBuilder::selectLiteral()` usage.

## 2.3.0

- Stored new recycle payloads below `.recycle/<timestamp>/<original-path>` while preserving the original basename.
