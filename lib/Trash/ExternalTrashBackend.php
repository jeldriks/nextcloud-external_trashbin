<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Trash;

use OCA\External_Trashbin\Db\TrashRecord;
use OCA\External_Trashbin\Db\TrashRecordMapper;
use OCA\External_Trashbin\RecycleManager;
use OCA\External_Trashbin\Storage\ExternalMount;
use OCA\Files_Trashbin\Trash\ITrashBackend;
use OCA\Files_Trashbin\Trash\ITrashItem;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\GenericFileException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class ExternalTrashBackend implements ITrashBackend {
    public function __construct(
        private TrashRecordMapper $mapper,
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    /** @return ITrashItem[] */
    public function listTrashRoot(IUser $user): array {
        $items = [];
        foreach ($this->mapper->findForUser($user->getUID()) as $record) {
            try {
                $items[] = $this->makeTrashItem($record, $user);
            } catch (\Throwable $e) {
                // Keep the DB row. A temporarily unavailable network mount should reappear later.
                $this->logger->debug('External trash item is currently unavailable', [
                    'app' => 'external_trashbin',
                    'recordId' => $record->getId(),
                    'mountPoint' => $record->getMountPoint(),
                    'exception' => $e,
                ]);
            }
        }

        return $items;
    }

    public function getTrashRootItem(IUser $user, string $name): ?ITrashItem {
        try {
            $record = $this->findRecordForDavName($user->getUID(), $name);
            return $this->makeTrashItem($record, $user);
        } catch (DoesNotExistException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->debug('Requested external trash item is unavailable', [
                'app' => 'external_trashbin',
                'trashName' => $name,
                'exception' => $e,
            ]);
            return null;
        }
    }

    public function listTrashFolder(ITrashItem $folder): array {
        // Folder contents remain inside the external resource, but are restored/deleted as one item.
        return [];
    }

    public function restoreItem(ITrashItem $item): void {
        $externalItem = $this->requireExternalItem($item);
        $record = $externalItem->getRecord();

        try {
            $source = $this->getSourceNode($record);
            $storage = $source->getStorage();
            $target = $this->getRestoreTarget($storage, $record);

            // Source and destination are on the same external storage. Using
            // Node::move() invokes Nextcloud's generic cache-move pipeline,
            // which can fail for external-trash entries whose metadata is null.
            // Rename the external-storage object directly and rebuild the affected cache
            // entries afterwards instead.
            $recycleManager = new RecycleManager($storage, $this->logger);
            if (!$recycleManager->restore($record->getRecyclePath(), $target)) {
                throw new GenericFileException('The external storage refused the restore rename');
            }

            $this->refreshCacheAfterRestore(
                $storage,
                $record->getRecyclePath(),
                $target,
                $record,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unable to restore external trash item', [
                'app' => 'external_trashbin',
                'recordId' => $record->getId(),
                'mountPoint' => $record->getMountPoint(),
                'recyclePath' => $record->getRecyclePath(),
                'originalPath' => $record->getOriginalPath(),
                'exception' => $e,
            ]);

            throw new GenericFileException('Unable to restore the external trash item: ' . $e->getMessage());
        }

        $this->mapper->delete($record);
    }

    public function removeItem(ITrashItem $item): void {
        $externalItem = $this->requireExternalItem($item);
        $this->removeRecord($externalItem->getRecord());
    }

    /**
     * Used by manual permanent deletion and retention expiry.
     * The metadata row is deleted only after the network copy is gone.
     */
    public function removeRecord(TrashRecord $record): void {
        try {
            $source = $this->getSourceNode($record);
            $source->delete();
        } catch (NotFoundException) {
            // The external file was already removed outside Nextcloud; clean stale metadata.
        } catch (\Throwable $e) {
            throw new GenericFileException(
                'The external resource is unavailable or refused permanent deletion: ' . $e->getMessage()
            );
        }

        $this->mapper->delete($record);
    }

    public function moveToTrash(IStorage $storage, string $internalPath): bool {
        // Move interception is handled by MoveToTrashEvent so only external mounts are affected.
        return false;
    }

    public function getTrashNodeById(IUser $user, int $fileId): ?Node {
        foreach ($this->listTrashRoot($user) as $item) {
            if ($item->getId() === $fileId) {
                try {
                    return $this->getSourceNode($this->requireExternalItem($item)->getRecord());
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }

    private function makeTrashItem(TrashRecord $record, IUser $user): ExternalTrashItem {
        $node = $this->getSourceNode($record);
        $deletedBy = $record->getDeletedBy() !== null
            ? $this->userManager->get($record->getDeletedBy())
            : null;

        return new ExternalTrashItem(
            $record,
            $this,
            $record->getOriginalLocation(),
            $record->getDeletedAt(),
            '/' . $record->getTrashName(),
            $node,
            $user,
            $deletedBy,
        );
    }


    private function findRecordForDavName(string $userId, string $name): TrashRecord {
        try {
            return $this->mapper->findForUserByTrashName($userId, $name);
        } catch (DoesNotExistException) {
            // Continue with the DAV form: <original-name>.d<deleted-time>.
        }

        if (!preg_match('/^(.*)\.d(\d+)$/', $name, $matches)) {
            throw new DoesNotExistException('External trash item not found');
        }

        $requestedBaseName = $matches[1];
        $deletedAt = (int)$matches[2];
        foreach ($this->mapper->findForUserByDeletedAt($userId, $deletedAt) as $record) {
            $originalBaseName = basename($record->getOriginalPath());
            if ($originalBaseName === $requestedBaseName || $record->getTrashName() === $requestedBaseName) {
                return $record;
            }
        }

        throw new DoesNotExistException('External trash item not found');
    }

    private function getSourceNode(TrashRecord $record): Node {
        // Initializes the user's external mounts before resolving the absolute mount path.
        $this->rootFolder->getUserFolder($record->getUserId());
        $fullPath = rtrim($record->getMountPoint(), '/') . '/' . ltrim($record->getRecyclePath(), '/');
        $node = $this->rootFolder->get($fullPath);

        if (!ExternalMount::isSupported($node->getMountPoint()->getMountType())) {
            throw new GenericFileException('Stored mount point is no longer an external mount');
        }

        $storage = $node->getStorage();
        $currentStorageId = method_exists($storage, 'getId') ? (string)$storage->getId() : get_class($storage);
        if ($record->getStorageId() !== $currentStorageId) {
            throw new GenericFileException('The external mount now points to a different storage');
        }

        return $node;
    }

    private function requireExternalItem(ITrashItem $item): ExternalTrashItem {
        if (!$item instanceof ExternalTrashItem) {
            throw new \LogicException('A foreign trash item was passed to the external trash backend');
        }
        return $item;
    }

    private function getRestoreTarget(IStorage $storage, TrashRecord $record): string {
        $target = ltrim($record->getOriginalPath(), '/');
        $parent = dirname($target);

        // If the original directory was removed while the item was in trash,
        // restore it to the root of the same external storage instead.
        if ($parent !== '.' && $parent !== '' && !$storage->is_dir($parent)) {
            $target = basename($target);
        }

        return $this->uniqueRestoreTarget($storage, $target);
    }

    private function uniqueRestoreTarget(IStorage $storage, string $target): string {
        if (!$storage->file_exists($target)) {
            return $target;
        }

        $info = pathinfo($target);
        $directory = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';
        $filename = $info['filename'] ?? basename($target);
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';

        for ($i = 1; $i <= 1000; $i++) {
            $candidate = $directory . $filename . ' (' . $i . ')' . $extension;
            if (!$storage->file_exists($candidate)) {
                return $candidate;
            }
        }

        return $directory . $filename . ' (' . bin2hex(random_bytes(4)) . ')' . $extension;
    }

    private function refreshCacheAfterRestore(
        IStorage $storage,
        string $recyclePath,
        string $targetPath,
        TrashRecord $record,
    ): void {
        try {
            $cache = $storage->getCache();

            // Remove stale source and target entries before scanning the real
            // restored object. Removing a folder entry also clears descendants.
            if ($cache->inCache($recyclePath)) {
                $cache->remove($recyclePath);
            }
            if ($cache->inCache($targetPath)) {
                $cache->remove($targetPath);
            }

            $storage->getScanner()->scan(
                $targetPath,
                \OC\Files\Cache\Scanner::SCAN_RECURSIVE,
            );
            $storage->getUpdater()->propagate($targetPath);
        } catch (\Throwable $e) {
            // The network rename has already succeeded. A cache refresh failure
            // must not turn a successful restore into a DAV 404. External
            // storage browsing or files:scan can rebuild the cache later.
            $this->logger->warning('External trash item restored, but cache refresh failed', [
                'app' => 'external_trashbin',
                'recordId' => $record->getId(),
                'recyclePath' => $recyclePath,
                'targetPath' => $targetPath,
                'exception' => $e,
            ]);
        }
    }

}
