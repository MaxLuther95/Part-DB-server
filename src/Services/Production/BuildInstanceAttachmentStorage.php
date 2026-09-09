<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildInstanceAttachment;
use App\Services\Attachments\AttachmentPathResolver;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class BuildInstanceAttachmentStorage
{
    public const MAX_ATTACHMENTS_PER_INSTANCE = 100;
    public const MAX_TOTAL_SIZE_PER_INSTANCE = 250 * 1024 * 1024;

    public function __construct(
        private AttachmentPathResolver $pathResolver,
        private OrderAttachmentStorage $attachmentValidator,
    ) {
    }

    public function validateUpload(UploadedFile $file): void
    {
        $this->attachmentValidator->validateUpload($file);
    }

    public function storeUpload(BuildInstance $buildInstance, UploadedFile $file): BuildInstanceAttachment
    {
        if (null === $buildInstance->getId()) {
            throw new \LogicException('The build instance must be persisted before storing an attachment.');
        }
        $this->validateUpload($file);
        $originalFilename = $this->attachmentValidator->sanitizeOriginalFilename($file->getClientOriginalName());
        $mimeType = strtolower((string) $file->getMimeType());
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $storedFilename = bin2hex(random_bytes(24)).'.'.$extension;
        $directory = $this->getDirectory($buildInstance);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Der sichere Ablageordner konnte nicht angelegt werden.');
        }

        $path = $directory.DIRECTORY_SEPARATOR.$storedFilename;
        try {
            $file->move($directory, $storedFilename);
            @chmod($path, 0640);
            $fileSize = filesize($path);
            if (false === $fileSize) {
                throw new \RuntimeException('Die Dateigröße konnte nach dem Speichern nicht geprüft werden.');
            }
            $checksum = hash_file('sha256', $path);
            if (false === $checksum) {
                throw new \RuntimeException('Die Prüfsumme konnte nach dem Speichern nicht ermittelt werden.');
            }
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw $exception;
        }

        $attachment = (new BuildInstanceAttachment())
            ->setBuildInstance($buildInstance)
            ->setOriginalFilename($originalFilename)
            ->setStoredFilename($storedFilename)
            ->setMimeType($mimeType)
            ->setFileSize((int) $fileSize)
            ->setSha256Checksum($checksum);
        $buildInstance->addAttachment($attachment);

        return $attachment;
    }

    public function getAbsolutePath(BuildInstanceAttachment $attachment): string
    {
        $buildInstance = $attachment->getBuildInstance() ?? throw new \LogicException('Attachment has no build instance.');
        $filename = basename($attachment->getStoredFilename());
        if ($filename !== $attachment->getStoredFilename() || 1 !== preg_match('/^[a-f0-9]{48}\.[a-z0-9]{1,8}$/D', $filename)) {
            throw new \RuntimeException('Ungültiger Dateipfad.');
        }
        $directory = $this->getDirectory($buildInstance);
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $resolvedDirectory = realpath($directory);
        $resolvedPath = realpath($path);
        if (false === $resolvedDirectory || false === $resolvedPath || ! str_starts_with($resolvedPath, $resolvedDirectory.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Die Datei liegt außerhalb des geschützten Ablageordners.');
        }

        return $resolvedPath;
    }

    public function remove(BuildInstanceAttachment $attachment): void
    {
        try {
            $path = $this->getAbsolutePath($attachment);
        } catch (\RuntimeException) {
            return;
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function getDirectory(BuildInstance $buildInstance): string
    {
        return rtrim($this->pathResolver->getSecurePath(), '/\\').DIRECTORY_SEPARATOR.'production-build-instances'.DIRECTORY_SEPARATOR.$buildInstance->getId();
    }
}
