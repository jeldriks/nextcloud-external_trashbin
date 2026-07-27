<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\AppInfo;

use OCA\External_Trashbin\Listener\EventListener;
use OCA\Files_Trashbin\Events\MoveToTrashEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\BeforeFileSystemSetupEvent;

final class Application extends App implements IBootstrap {
    public const APP_ID = 'external_trashbin';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(BeforeFileSystemSetupEvent::class, EventListener::class);
        $context->registerEventListener(MoveToTrashEvent::class, EventListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
