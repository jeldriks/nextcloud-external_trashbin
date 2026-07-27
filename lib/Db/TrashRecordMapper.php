<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @extends QBMapper<TrashRecord> */
final class TrashRecordMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'external_trash_items', TrashRecord::class);
    }

    /** @return TrashRecord[] */
    public function findForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('deleted_at', 'DESC');

        return $this->findEntities($qb);
    }

    /** @return TrashRecord[] */
    public function findAllRecords(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('deleted_at', 'ASC');

        return $this->findEntities($qb);
    }



    public function trashNameExistsForUser(string $userId, string $trashName, ?int $excludeId = null): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('trash_name', $qb->createNamedParameter($trashName)))
            ->setMaxResults(1);

        if ($excludeId !== null) {
            $qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId)));
        }

        return $qb->executeQuery()->fetchOne() !== false;
    }

    /** @return TrashRecord[] */
    public function findForUserByDeletedAt(string $userId, int $deletedAt): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter($deletedAt)));

        return $this->findEntities($qb);
    }

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findForUserByTrashName(string $userId, string $trashName): TrashRecord {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('trash_name', $qb->createNamedParameter($trashName)));

        return $this->findEntity($qb);
    }
}
