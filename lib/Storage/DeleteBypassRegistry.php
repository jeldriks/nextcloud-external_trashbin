<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Storage;

use OCP\Files\Storage\IStorage;

/**
 * Request-local registry for source paths that were already moved to .recycle.
 *
 * The original Nextcloud delete continues after MoveToTrashEvent. The wrapper
 * consumes the matching entry and reports that one delete as successful. No
 * other external-storage delete behavior is changed.
 */
final class DeleteBypassRegistry {
    /** @var array<string, true> */
    private static array $paths = [];

    public static function mark(IStorage $storage, string $path): void {
        self::$paths[self::key($storage, $path)] = true;
    }

    public static function consume(IStorage $storage, string $path): bool {
        $key = self::key($storage, $path);
        if (!isset(self::$paths[$key])) {
            return false;
        }

        unset(self::$paths[$key]);
        return true;
    }

    private static function key(IStorage $storage, string $path): string {
        $storageId = method_exists($storage, 'getId')
            ? (string)$storage->getId()
            : get_class($storage);

        return $storageId . "\0" . ltrim($path, '/');
    }
}
