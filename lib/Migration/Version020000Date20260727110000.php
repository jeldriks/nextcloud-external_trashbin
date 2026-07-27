<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version020000Date20260727110000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('external_trash_items')) {
            return null;
        }

        $table = $schema->createTable('external_trash_items');
        $table->addColumn('id', 'bigint', [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
        $table->addColumn('deleted_by', 'string', ['notnull' => false, 'length' => 64]);
        $table->addColumn('mount_point', 'string', ['notnull' => true, 'length' => 1024]);
        $table->addColumn('storage_id', 'string', ['notnull' => true, 'length' => 255]);
        $table->addColumn('original_path', 'text', ['notnull' => true]);
        $table->addColumn('original_location', 'text', ['notnull' => true]);
        $table->addColumn('recycle_path', 'text', ['notnull' => true]);
        $table->addColumn('trash_name', 'string', ['notnull' => true, 'length' => 255]);
        $table->addColumn('deleted_at', 'bigint', ['notnull' => true, 'unsigned' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'ext_trash_user_idx');
        $table->addUniqueIndex(['user_id', 'trash_name'], 'ext_trash_name_uidx');
        $table->addIndex(['deleted_at'], 'ext_trash_time_idx');

        return $schema;
    }
}
