<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Listener;

use OC\Files\Filesystem;
use OCA\External_Trashbin\Db\TrashRecord;
use OCA\External_Trashbin\Db\TrashRecordMapper;
use OCA\External_Trashbin\RecycleManager;
use OCA\External_Trashbin\Storage\DeleteBypassRegistry;
use OCA\External_Trashbin\Storage\ExternalMount;
use OCA\External_Trashbin\Wrapper\ExternalRecycleWrapper;
use OCA\Files_Trashbin\Events\MoveToTrashEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeFileSystemSetupEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorage;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<Event> */
final class EventListener implements IEventListener {
    private static bool $handlingMove = false;

    public function __construct(
        private TrashRecordMapper $mapper,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof BeforeFileSystemSetupEvent) {
            $this->registerExternalStorageWrapper();
            return;
        }

        if ($event instanceof MoveToTrashEvent) {
            $this->handleMoveToTrash($event);
        }
    }

    private function registerExternalStorageWrapper(): void {
        Filesystem::addStorageWrapper(
            'external_trashbin',
            static function (string $mountPoint, IStorage $storage, IMountPoint $mount): IStorage {
                if (!ExternalMount::isSupported($mount->getMountType())) {
                    return $storage;
                }

                return new ExternalRecycleWrapper([
                    'storage' => $storage,
                    'mountPoint' => $mountPoint,
                ]);
            },
            0,
        );
    }

    private function handleMoveToTrash(MoveToTrashEvent $event): void {
        if (self::$handlingMove) {
            return;
        }

        $node = $event->getNode();
        $mount = $node->getMountPoint();
        if (!ExternalMount::isSupported($mount->getMountType())) {
            return;
        }

        $internalPath = ltrim($node->getInternalPath(), '/');
        if ($internalPath === '') {
            return;
        }

        $storage = $node->getStorage();
        $recycleManager = new RecycleManager($storage, $this->logger);

        // Deleting from .recycle means permanent deletion. Never recycle it again.
        if ($recycleManager->isInRecycle($internalPath)) {
            $event->disableTrashBin();
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            // Without a trash owner we cannot create a safe Nextcloud trash record.
            return;
        }

        $deletedAt = $this->timeFactory->getTime();
        $baseName = basename($internalPath);
        while ($this->mapper->trashNameExistsForUser(
            $user->getUID(),
            $baseName . '.d' . $deletedAt,
        )) {
            $deletedAt++;
        }

        self::$handlingMove = true;
        try {
            $moveResult = $recycleManager->moveToRecycle($internalPath, $deletedAt);
            if ($moveResult === null) {
                return;
            }
            $recyclePath = $moveResult['path'];
            $deletedAt = $moveResult['deletedAt'];

            $record = new TrashRecord();
            $record->setUserId($user->getUID());
            $record->setDeletedBy($user->getUID());
            $record->setMountPoint($mount->getMountPoint());
            $record->setStorageId($this->getStorageIdentity($storage));
            $record->setOriginalPath($internalPath);
            $record->setOriginalLocation($this->getUserRelativePath(
                $node->getPath(),
                $user->getUID(),
                $internalPath,
            ));
            $record->setRecyclePath($recyclePath);
            $record->setTrashName($baseName . '.d' . $deletedAt);
            $record->setDeletedAt($deletedAt);

            try {
                $this->mapper->insert($record);
            } catch (\Throwable $e) {
                $rolledBack = $recycleManager->restore($recyclePath, $internalPath);
                $this->logger->error('Failed to register external trash item in Nextcloud', [
                    'app' => 'external_trashbin',
                    'source' => $internalPath,
                    'recyclePath' => $recyclePath,
                    'rolledBack' => $rolledBack,
                    'exception' => $e,
                ]);

                // If rollback failed, suppress the original delete so the orphaned recycle copy is preserved.
                if (!$rolledBack) {
                    DeleteBypassRegistry::mark($storage, $internalPath);
                    $event->disableTrashBin();
                }
                return;
            }

            DeleteBypassRegistry::mark($storage, $internalPath);
            $event->disableTrashBin();
        } catch (\Throwable $e) {
            $this->logger->error('External trash move failed; default trash handling will be used', [
                'app' => 'external_trashbin',
                'path' => $internalPath,
                'exception' => $e,
            ]);
        } finally {
            self::$handlingMove = false;
        }
    }

    private function getUserRelativePath(string $nodePath, string $userId, string $fallback): string {
        try {
            $userFolderPath = rtrim($this->rootFolder->getUserFolder($userId)->getPath(), '/') . '/';
            if (str_starts_with($nodePath, $userFolderPath)) {
                return ltrim(substr($nodePath, strlen($userFolderPath)), '/');
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to derive the user-relative external trash location', [
                'app' => 'external_trashbin',
                'userId' => $userId,
                'exception' => $e,
            ]);
        }

        return ltrim($fallback, '/');
    }

    private function getStorageIdentity(IStorage $storage): string {
        return method_exists($storage, 'getId')
            ? (string)$storage->getId()
            : get_class($storage);
    }
}
