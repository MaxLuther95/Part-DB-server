<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\CustomerProject;
use App\Entity\Production\OrderAttachment;
use App\Services\Attachments\AttachmentPathResolver;
use App\Services\Attachments\AttachmentSubmitHandler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class OrderAttachmentStorage
{
    public const MAX_ATTACHMENTS_PER_ORDER = 100;
    public const MAX_TOTAL_SIZE_PER_ORDER = 250 * 1024 * 1024;

    /** @var array<string, list<string>> */
    private const ALLOWED_TYPES = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'eml' => ['message/rfc822', 'text/plain'],
    ];

    public function __construct(
        private AttachmentPathResolver $pathResolver,
        private AttachmentSubmitHandler $attachmentSubmitHandler,
    )
    {
    }

    public function getMaximumFileSize(): int
    {
        return $this->attachmentSubmitHandler->getMaximumEffectiveUploadSize();
    }

    public function validateUpload(UploadedFile $file, bool $pdfOnly = false): void
    {
        if (!$file->isValid() || !is_file($file->getPathname())) {
            throw new \InvalidArgumentException('Die hochgeladene Datei ist ungültig.');
        }
        $size = $file->getSize();
        $maximum = $this->getMaximumFileSize();
        if (!is_int($size) || $size < 1 || $size > $maximum) {
            throw new \InvalidArgumentException(sprintf('Die Datei muss zwischen 1 Byte und %d MB groß sein.', intdiv($maximum, 1024 * 1024)));
        }

        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowed = $pdfOnly ? ['pdf' => self::ALLOWED_TYPES['pdf']] : self::ALLOWED_TYPES;
        if (!isset($allowed[$extension])) {
            throw new \InvalidArgumentException('Dieser Dateityp ist nicht erlaubt. Zulässig sind PDF, PNG, JPG, WEBP, TXT, CSV, DOCX, XLSX, ODT, ODS und EML.');
        }
        $detectedMimeType = strtolower((string) $file->getMimeType());
        if (!in_array($detectedMimeType, $allowed[$extension], true)) {
            throw new \InvalidArgumentException('Dateiendung und tatsächlich erkannter Dateiinhalt passen nicht zusammen.');
        }
        if ('pdf' === $extension) {
            $handle = fopen($file->getPathname(), 'rb');
            $header = false === $handle ? false : fread($handle, 4096);
            if (is_resource($handle)) {
                fseek($handle, max(0, $size - 4096));
            }
            $trailer = false === $handle ? false : fread($handle, 4096);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!is_string($header) || !str_starts_with($header, '%PDF-') || !str_contains($header, ' obj') || !is_string($trailer) || !str_contains($trailer, '%%EOF')) {
                throw new \InvalidArgumentException('Die Datei besitzt keine vollständige PDF-Struktur.');
            }
        }
        if (in_array($extension, ['docx', 'xlsx', 'odt', 'ods'], true)) {
            $this->validateOfficeArchive($file->getPathname(), $extension);
        }
    }

    public function storeUpload(CustomerProject $order, UploadedFile $file): OrderAttachment
    {
        if (null === $order->getId()) {
            throw new \LogicException('The order must be persisted before storing an attachment.');
        }
        $this->validateUpload($file);

        $originalFilename = $this->sanitizeOriginalFilename($file->getClientOriginalName());
        $mimeType = strtolower((string) $file->getMimeType());
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $storedFilename = bin2hex(random_bytes(24)).'.'.$extension;
        $directory = $this->getOrderDirectory($order);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
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
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw $exception;
        }

        $attachment = (new OrderAttachment())
            ->setOrder($order)
            ->setOriginalFilename($originalFilename)
            ->setStoredFilename($storedFilename)
            ->setMimeType($mimeType)
            ->setFileSize((int) $fileSize);
        $order->addAttachment($attachment);

        return $attachment;
    }

    public function adoptPdf(CustomerProject $order, string $temporaryPath, string $originalFilename): OrderAttachment
    {
        $file = new UploadedFile($temporaryPath, $originalFilename, 'application/pdf', null, true);
        $this->validateUpload($file, true);

        return $this->storeUpload($order, $file);
    }

    public function getAbsolutePath(OrderAttachment $attachment): string
    {
        $order = $attachment->getOrder() ?? throw new \LogicException('Attachment has no order.');
        $filename = basename($attachment->getStoredFilename());
        if ($filename !== $attachment->getStoredFilename() || 1 !== preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{48})\.[a-z0-9]{1,8}$/D', $filename)) {
            throw new \RuntimeException('Ungültiger Dateipfad.');
        }
        $directory = $this->getOrderDirectory($order);
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $resolvedDirectory = realpath($directory);
        $resolvedPath = realpath($path);
        if (false === $resolvedDirectory || false === $resolvedPath || !str_starts_with($resolvedPath, $resolvedDirectory.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Die Datei liegt außerhalb des geschützten Ablageordners.');
        }

        return $resolvedPath;
    }

    public function remove(OrderAttachment $attachment): void
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

    private function getOrderDirectory(CustomerProject $order): string
    {
        return rtrim($this->pathResolver->getSecurePath(), '/\\').DIRECTORY_SEPARATOR.'production-orders'.DIRECTORY_SEPARATOR.$order->getId();
    }

    private function sanitizeOriginalFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");
        if ('' === $filename) {
            $filename = 'datei';
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $base = pathinfo($filename, PATHINFO_FILENAME);

        return mb_substr($base, 0, 160).('' !== $extension ? '.'.$extension : '');
    }

    private function validateOfficeArchive(string $path, string $extension): void
    {
        $archive = new \ZipArchive();
        if (true !== $archive->open($path, \ZipArchive::RDONLY)) {
            throw new \InvalidArgumentException('Die Office-Datei besitzt keine gültige Dokumentstruktur.');
        }
        try {
            $valid = match ($extension) {
                'docx' => false !== $archive->locateName('[Content_Types].xml') && false !== $archive->locateName('word/document.xml'),
                'xlsx' => false !== $archive->locateName('[Content_Types].xml') && false !== $archive->locateName('xl/workbook.xml'),
                'odt' => 'application/vnd.oasis.opendocument.text' === $archive->getFromName('mimetype'),
                'ods' => 'application/vnd.oasis.opendocument.spreadsheet' === $archive->getFromName('mimetype'),
                default => false,
            };
        } finally {
            $archive->close();
        }
        if (!$valid) {
            throw new \InvalidArgumentException('Dateiendung und interne Office-Dokumentstruktur passen nicht zusammen.');
        }
    }
}
