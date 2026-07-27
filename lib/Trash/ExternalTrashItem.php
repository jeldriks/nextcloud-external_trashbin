<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Trash;

use OCA\External_Trashbin\Db\TrashRecord;
use OCA\Files_Trashbin\Trash\TrashItem;

/**
 * Exposes the original filename in Nextcloud while keeping the physical object
 * at the exact path recorded in the external-trash metadata.
 *
 * Nextcloud's Sabre trash file appends `.d<deletedTime>` when opening a file.
 * ExternalRecycleWrapper translates that DAV-only suffix back to the physical
 * path, so the object stored below `.recycle` can keep its original basename.
 */
final class ExternalTrashItem extends TrashItem {
    private string $displayName;
    private string $physicalInternalPath;

    public function __construct(
        private TrashRecord $record,
        ExternalTrashBackend $backend,
        string $originalLocation,
        int $deletedTime,
        string $trashPath,
        \OCP\Files\FileInfo $fileInfo,
        \OCP\IUser $user,
        ?\OCP\IUser $deletedBy,
    ) {
        parent::__construct(
            $backend,
            $originalLocation,
            $deletedTime,
            $trashPath,
            $fileInfo,
            $user,
            $deletedBy,
        );

        $this->displayName = basename($record->getOriginalPath());
        $this->physicalInternalPath = $fileInfo->getInternalPath();
    }

    public function getRecord(): TrashRecord {
        return $this->record;
    }

    /**
     * TrashFile/TrashFolder append the deletion suffix to the DAV name.
     */
    public function getName(): string {
        return $this->displayName;
    }

    /**
     * Return the exact physical path. The storage wrapper removes the DAV-only
     * suffix that Nextcloud appends before fopen().
     */
    public function getInternalPath(): string {
        return $this->physicalInternalPath;
    }
}
