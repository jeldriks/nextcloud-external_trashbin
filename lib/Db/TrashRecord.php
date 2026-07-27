<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $value)
 * @method string|null getDeletedBy()
 * @method void setDeletedBy(?string $value)
 * @method string getMountPoint()
 * @method void setMountPoint(string $value)
 * @method string getStorageId()
 * @method void setStorageId(string $value)
 * @method string getOriginalPath()
 * @method void setOriginalPath(string $value)
 * @method string getOriginalLocation()
 * @method void setOriginalLocation(string $value)
 * @method string getRecyclePath()
 * @method void setRecyclePath(string $value)
 * @method string getTrashName()
 * @method void setTrashName(string $value)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $value)
 */
final class TrashRecord extends Entity {
    protected $userId = '';
    protected $deletedBy = null;
    protected $mountPoint = '';
    protected $storageId = '';
    protected $originalPath = '';
    protected $originalLocation = '';
    protected $recyclePath = '';
    protected $trashName = '';
    protected $deletedAt = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('deletedBy', 'string');
        $this->addType('mountPoint', 'string');
        $this->addType('storageId', 'string');
        $this->addType('originalPath', 'string');
        $this->addType('originalLocation', 'string');
        $this->addType('recyclePath', 'string');
        $this->addType('trashName', 'string');
        $this->addType('deletedAt', 'integer');
    }
}
