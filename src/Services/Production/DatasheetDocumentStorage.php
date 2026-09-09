<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\DatasheetDocument;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final readonly class DatasheetDocumentStorage
{
    private const MAX_PDF_SIZE = 25_000_000;

    public function __construct(
        #[Autowire('%kernel.project_dir%/uploads/production_datasheets')]
        private string $storageDirectory,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @param array<string, mixed> $sourceSnapshot
     */
    public function store(string $pdf, string $filename, array $sourceSnapshot): DatasheetDocument
    {
        $size = strlen($pdf);
        if (! str_starts_with($pdf, '%PDF-') || 0 === $size || self::MAX_PDF_SIZE < $size) {
            throw new \RuntimeException('The generated datasheet is not a valid PDF or exceeds the size limit.');
        }
        $this->filesystem->mkdir($this->storageDirectory, 0o770);
        $storedFilename = bin2hex(random_bytes(24)).'.pdf';
        $path = $this->pathForName($storedFilename);
        if (false === file_put_contents($path, $pdf, LOCK_EX)) {
            throw new \RuntimeException('The generated datasheet could not be stored.');
        }
        chmod($path, 0o440);

        return (new DatasheetDocument())
            ->setOriginalFilename($this->safeFilename($filename))
            ->setStoredFilename($storedFilename)
            ->setFileSize($size)
            ->setSha256Checksum(hash('sha256', $pdf))
            ->setSourceSnapshot($sourceSnapshot);
    }

    public function createDownloadResponse(DatasheetDocument $document): BinaryFileResponse
    {
        $path = $this->pathForName($document->getStoredFilename());
        if (! is_file($path)) {
            throw new \RuntimeException('The stored datasheet file is missing.');
        }
        if (! hash_equals($document->getSha256Checksum(), hash_file('sha256', $path) ?: '')) {
            throw new \RuntimeException('The stored datasheet failed its integrity check.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $document->getOriginalFilename()));
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function remove(DatasheetDocument $document): void
    {
        $this->filesystem->remove($this->pathForName($document->getStoredFilename()));
    }

    private function pathForName(string $storedFilename): string
    {
        if (1 !== preg_match('/^[a-f0-9]{48}\.pdf$/D', $storedFilename)) {
            throw new \InvalidArgumentException('Invalid stored datasheet filename.');
        }

        return $this->storageDirectory.DIRECTORY_SEPARATOR.$storedFilename;
    }

    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'datasheet.pdf';
        $filename = trim($filename, '.-');

        return str_ends_with(strtolower($filename), '.pdf') ? $filename : $filename.'.pdf';
    }
}
