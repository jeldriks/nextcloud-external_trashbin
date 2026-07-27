<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Storage;

/**
 * Marker used only to register an additional trash-list backend.
 * No real storage extends this class, so normal TrashManager routing is unaffected.
 */
final class BackendMarker {
}
