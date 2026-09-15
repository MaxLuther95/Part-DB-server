<?php

declare(strict_types=1);

namespace App\Tests\Services\Attachments;

use App\Entity\Attachments\AttachmentUpload;
use App\Entity\Attachments\PartAttachment;
use App\Entity\Parts\Part;
use App\Services\Attachments\AttachmentPathResolver;
use App\Services\Attachments\AttachmentSubmitHandler;
use App\Services\Attachments\FileTypeFilterTools;
use App\Services\Attachments\SVGSanitizer;
use App\Settings\SystemSettings\AttachmentsSettings;
use App\Tests\SettingsTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

final class PrivateAttachmentUploadTest extends TestCase
{
    public function testPublicUploadAndMoveRequestsCannotOverridePrivatePolicy(): void
    {
        $directory = sys_get_temp_dir().'/partdb-private-upload-'.bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir([$directory.'/public/media', $directory.'/uploads'], 0700);
        try {
            $paths = new AttachmentPathResolver($directory, 'public/media', 'uploads', 'public/media');
            $settings = SettingsTestHelper::createSettingsDummy(AttachmentsSettings::class);
            $settings->forcePrivateAttachments = true;
            $mime = MimeTypes::getDefault();
            $handler = new AttachmentSubmitHandler($paths, new MockHttpClient(), $mime, new FileTypeFilterTools($mime, new ArrayAdapter()), $settings, new SVGSanitizer());
            $attachment = (new PartAttachment())->setName('Private upload')->setElement((new Part())->setName('Test'));
            $fs->dumpFile($directory.'/source.txt', 'Keep this private');
            $handler->handleUpload($attachment, new AttachmentUpload(file: new UploadedFile($directory.'/source.txt', 'source.txt', 'text/plain', null, true), private: false));
            self::assertTrue($attachment->isSecure());
            $path = $paths->placeholderToRealPath($attachment->getInternalPath());
            self::assertStringStartsWith(realpath($directory.'/uploads').'/', $path);
            self::assertSame('Keep this private', file_get_contents($path));
            $handler->handleUpload($attachment, new AttachmentUpload(file: null, private: false));
            self::assertTrue($attachment->isSecure());
            self::assertFileExists($path);
            self::assertSame([], iterator_to_array((new \Symfony\Component\Finder\Finder())->files()->in($directory.'/public/media')));
        } finally {
            $fs->remove($directory);
        }
    }
}
