<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\BackgroundJob;

use OCA\External_Trashbin\Db\TrashRecordMapper;
use OCA\External_Trashbin\Trash\ExternalTrashBackend;
use OCA\Files_Trashbin\Expiration;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

final class ExpireExternalTrash extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private TrashRecordMapper $mapper,
        private ExternalTrashBackend $backend,
        private Expiration $expiration,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(30 * 60);
    }

    protected function run($argument): void {
        foreach ($this->mapper->findAllRecords() as $record) {
            if (!$this->expiration->isExpired($record->getDeletedAt(), false)) {
                continue;
            }

            try {
                $this->backend->removeRecord($record);
            } catch (\Throwable $e) {
                // Keep metadata and retry on the next run when the network resource is available.
                $this->logger->warning('Unable to expire external trash item', [
                    'app' => 'external_trashbin',
                    'recordId' => $record->getId(),
                    'mountPoint' => $record->getMountPoint(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
