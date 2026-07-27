<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Wrapper;

use OC\Files\Storage\Wrapper\Wrapper;
use OCA\External_Trashbin\RecycleManager;
use OCA\External_Trashbin\Storage\DeleteBypassRegistry;

/**
 * Adapts external storage to the custom trash backend.
 *
 * - Makes the original delete succeed after the listener already moved it.
 * - Resolves Nextcloud's DAV-only `.d<timestamp>` download suffix to the exact
 *   physical file recorded below `.recycle`.
 */
final class ExternalRecycleWrapper extends Wrapper {
    /** @return resource|false */
    public function fopen(string $path, string $mode) {
        return $this->storage->fopen($this->resolveDavTrashPath($path), $mode);
    }

    public function unlink(string $path): bool {
        if (DeleteBypassRegistry::consume($this, $path)) {
            return true;
        }

        return $this->storage->unlink($path);
    }

    public function rmdir(string $path): bool {
        if (DeleteBypassRegistry::consume($this, $path)) {
            return true;
        }

        return $this->storage->rmdir($path);
    }

    private function resolveDavTrashPath(string $path): string {
        $path = ltrim($path, '/');
        $recyclePrefix = RecycleManager::RECYCLE_ROOT . '/';
        if (!str_starts_with($path, $recyclePrefix)) {
            return $path;
        }

        // Direct paths are always preferred. This keeps ordinary external-storage access
        // and all legacy external-trash layouts untouched.
        if ($this->storage->file_exists($path)) {
            return $path;
        }

        // Nextcloud TrashFile opens `<physical-path>.d<deletedTime>`. Remove
        // only the final DAV suffix and use the physical object when present.
        if (preg_match('/^(.*)\.d\d+$/', $path, $matches) === 1) {
            $physicalPath = $matches[1];
            if ($this->storage->file_exists($physicalPath)) {
                return $physicalPath;
            }
        }

        return $path;
    }
}
