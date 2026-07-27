<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Storage;

/**
 * Centralizes the Nextcloud mount types handled by this app.
 */
final class ExternalMount {
    public const TYPE_EXTERNAL = 'external';
    public const TYPE_EXTERNAL_SESSION = 'external-session';

    public static function isSupported(?string $mountType): bool {
        return $mountType === self::TYPE_EXTERNAL
            || $mountType === self::TYPE_EXTERNAL_SESSION;
    }
}
