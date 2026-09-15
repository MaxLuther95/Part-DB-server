<?php

declare(strict_types=1);

namespace App\Services\Attachments;

use App\Entity\UserSystem\User;
use App\Services\UserSystem\PermissionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/** Offline migration: callers must stop HTTP writers and take a database backup. */
final readonly class PrivateMediaMigration
{
    public function __construct(
        private AttachmentPathResolver $paths,
        private EntityManagerInterface $entityManager,
        private PermissionManager $permissions,
        private Filesystem $filesystem,
    ) {
    }

    /** @return array{attachments: int, public_files: int, archive: ?string} */
    public function run(bool $apply = false): array
    {
        $media = realpath($this->paths->getMediaPath());
        $secure = realpath($this->paths->getSecurePath());
        if (false === $media || false === $secure || $media === $secure
            || '/' === $media || '/' === $secure
            || str_starts_with($secure.'/', $media.'/') || str_starts_with($media.'/', $secure.'/')) {
            throw new \RuntimeException('Distinct existing public and private storage directories are required.');
        }
        $connection = $this->entityManager->getConnection();
        $records = [];
        foreach ($connection->fetchAllAssociative('SELECT id, internal_path FROM attachments') as $row) {
            $old = $row['internal_path'];
            if (!is_string($old) || (!str_starts_with($old, '%MEDIA%/') && !str_starts_with($old, '%BASE%/data/media/'))) {
                continue;
            }
            $path = $this->paths->placeholderToRealPath($old);
            $resolved = null === $path ? false : realpath($path);
            if (false === $resolved || !is_file($resolved) || !str_starts_with($resolved, $media.'/')) {
                throw new \RuntimeException('A public attachment is missing or has an unsafe path. Migration aborted without deleting records.');
            }
            $relative = substr($resolved, strlen($media) + 1);
            $destination = $secure.'/'.$relative;
            $this->assertPrivateDestination($destination);
            if (file_exists($destination) && !$this->sameFile($resolved, $destination)) {
                throw new \RuntimeException('A private destination already contains different data. Resolve the conflict before migrating.');
            }
            $records[] = ['id' => (int) $row['id'], 'old' => $old, 'new' => '%SECURE%/'.$relative, 'source' => $resolved, 'target' => $destination];
        }
        $files = [];
        foreach ((new Finder())->in($media)->ignoreDotFiles(false)->ignoreVCS(false) as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException('Public media contains a symbolic link. Resolve it before migrating.');
            }
            if ($file->isFile()) {
                $files[$file->getPathname()] = $file->getRelativePathname();
            }
        }
        if (!$apply) {
            return ['attachments' => count($records), 'public_files' => count($files), 'archive' => null];
        }
        $archive = $secure.'/private-media-migration-'.bin2hex(random_bytes(12));
        $this->filesystem->mkdir($archive, 0700);
        // Keep every original, including orphaned files and thumbnail caches,
        // outside the web root. Never delete a file without a verified copy.
        foreach ($files as $source => $relative) {
            $this->copyVerified($source, $archive.'/media/'.$relative);
        }
        foreach ($records as $record) {
            $this->copyVerified($record['source'], $record['target']);
        }
        $this->filesystem->dumpFile($archive.'/references.json', json_encode($records, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($archive.'/references.json', 0600);

        $connection->beginTransaction();
        try {
            foreach ($records as $record) {
                $changed = $connection->executeStatement('UPDATE attachments SET internal_path = ? WHERE id = ? AND internal_path = ?', [$record['new'], $record['id'], $record['old']]);
                if (1 !== $changed) {
                    throw new \RuntimeException('An attachment changed during migration. Keep the application offline and investigate.');
                }
            }
            $anonymous = $this->entityManager->find(User::class, User::ID_ANONYMOUS);
            if (!$anonymous instanceof User) {
                throw new \RuntimeException('The anonymous account is missing.');
            }
            foreach ($this->permissions->getPermissionStructure()['perms'] as $area => $definition) {
                foreach (array_keys($definition['operations']) as $operation) {
                    $anonymous->getPermissions()->setPermissionValue($area, $operation, false);
                }
            }
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
        // Check again immediately before removal. On any interruption the
        // private copies and archive remain usable; an offline rerun is safe.
        foreach ($files as $source => $relative) {
            if (!$this->sameFile($source, $archive.'/media/'.$relative)) {
                throw new \RuntimeException('Public media changed during migration. Keep the application offline.');
            }
        }
        foreach (array_keys($files) as $source) {
            $this->filesystem->remove($source);
        }

        return ['attachments' => count($records), 'public_files' => count($files), 'archive' => $archive];
    }

    private function sameFile(string $source, string $target): bool
    {
        if (!is_file($source) || !is_file($target) || is_link($target)) {
            return false;
        }
        $hash = hash_file('sha256', $source);

        return false !== $hash && hash_equals($hash, hash_file('sha256', $target) ?: '');
    }

    private function copyVerified(string $source, string $target): void
    {
        $this->assertPrivateDestination($target);
        if (file_exists($target) && !$this->sameFile($source, $target)) {
            throw new \RuntimeException('Refusing to overwrite different data in private storage.');
        }
        $this->filesystem->mkdir(dirname($target), 0700);
        if (!file_exists($target)) {
            $this->filesystem->copy($source, $target);
            chmod($target, 0600);
        }
        if (!$this->sameFile($source, $target)) {
            throw new \RuntimeException('A private copy failed its checksum verification.');
        }
    }

    private function assertPrivateDestination(string $target): void
    {
        $secure = realpath($this->paths->getSecurePath());
        $ancestor = $target;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $ancestor = dirname($ancestor);
        }
        $resolved = realpath($ancestor);
        if (false === $secure || false === $resolved || is_link($ancestor)
            || ($resolved !== $secure && !str_starts_with($resolved, $secure.'/'))) {
            throw new \RuntimeException('A destination escapes private storage. Migration aborted.');
        }
    }
}
