<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin;

use OCP\Files\Storage\IStorage;
use Psr\Log\LoggerInterface;

final class RecycleManager {
    public const RECYCLE_ROOT = '.recycle';

    public function __construct(
        private IStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Move an item into a timestamp-specific directory while preserving the
     * original basename exactly.
     *
     * Example:
     *   source: reports/annual.pdf
     *   target: .recycle/1785182216/reports/annual.pdf
     *
     * @return array{path: string, deletedAt: int}|null Exact destination and
     * adjusted deletion timestamp, or null when the move failed.
     */
    public function moveToRecycle(string $sourcePath, int $deletedAt): ?array {
        $sourcePath = ltrim($sourcePath, '/');
        [$destination, $deletedAt] = $this->findAvailableDestination($sourcePath, $deletedAt);

        if (!$this->ensureDirForPath($destination)) {
            $this->logger->error('Failed to create external recycle directory', [
                'app' => 'external_trashbin',
                'destination' => $destination,
            ]);
            return null;
        }

        try {
            if ($this->storage->rename($sourcePath, $destination)) {
                return ['path' => $destination, 'deletedAt' => $deletedAt];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('External recycle rename failed; trying copy/delete', [
                'app' => 'external_trashbin',
                'source' => $sourcePath,
                'destination' => $destination,
                'exception' => $e,
            ]);
        }

        // File-only fallback. Directory copies are intentionally not attempted recursively.
        try {
            if (!$this->storage->is_dir($sourcePath) && $this->storage->copy($sourcePath, $destination)) {
                if ($this->storage->unlink($sourcePath)) {
                    return ['path' => $destination, 'deletedAt' => $deletedAt];
                }
                $this->storage->unlink($destination);
            }
        } catch (\Throwable $e) {
            $this->logger->error('External recycle copy/delete fallback failed', [
                'app' => 'external_trashbin',
                'source' => $sourcePath,
                'destination' => $destination,
                'exception' => $e,
            ]);
        }

        return null;
    }

    public function restore(string $recyclePath, string $originalPath): bool {
        if (!$this->ensureDirForPath($originalPath)) {
            return false;
        }

        try {
            return $this->storage->rename($recyclePath, $originalPath);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to roll external recycle item back', [
                'app' => 'external_trashbin',
                'recyclePath' => $recyclePath,
                'originalPath' => $originalPath,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function isInRecycle(string $path): bool {
        $path = ltrim($path, '/');
        return $path === self::RECYCLE_ROOT || str_starts_with($path, self::RECYCLE_ROOT . '/');
    }

    public static function getRecyclePath(string $sourcePath, int $deletedAt): string {
        $sourcePath = ltrim($sourcePath, '/');
        return self::RECYCLE_ROOT . '/' . $deletedAt . '/' . $sourcePath;
    }

    /** @return array{string, int} */
    private function findAvailableDestination(string $sourcePath, int $deletedAt): array {
        for ($attempt = 0; $attempt < 10000; $attempt++, $deletedAt++) {
            $candidate = self::getRecyclePath($sourcePath, $deletedAt);
            if (!$this->storage->file_exists($candidate)) {
                return [$candidate, $deletedAt];
            }
        }

        throw new \RuntimeException('Unable to allocate a unique external trash timestamp');
    }

    private function ensureDirForPath(string $filePath): bool {
        $directory = dirname(ltrim($filePath, '/'));
        if ($directory === '.' || $directory === '') {
            return true;
        }

        $current = '';
        foreach (explode('/', $directory) as $part) {
            if ($part === '') {
                continue;
            }
            $current = $current === '' ? $part : $current . '/' . $part;
            if (!$this->storage->is_dir($current) && !$this->storage->mkdir($current)) {
                return false;
            }
        }

        return true;
    }
}
