<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Attachments\PartAttachment;
use App\Entity\Attachments\AttachmentType;
use App\Entity\Parts\Part;
use App\Entity\UserSystem\User;
use App\Services\Attachments\AttachmentPathResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PrivateAttachmentAccessTest extends WebTestCase
{
    public function testPrivateFileRequiresPermissionAndRemainsReadableToAuthorizedUsers(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $paths = self::getContainer()->get(AttachmentPathResolver::class);
        $relative = 'security-test-'.bin2hex(random_bytes(12)).'/sample.txt';
        $path = $paths->getSecurePath().'/'.$relative;
        $fs = new Filesystem();
        $fs->dumpFile($path, 'Private attachment test');
        try {
            $part = $em->find(Part::class, 1);
            $attachment = (new PartAttachment())->setName('Private test document')->setElement($part)->setAttachmentType($em->find(AttachmentType::class, 1))->setInternalPath('%SECURE%/'.$relative);
            $em->persist($attachment);
            $em->flush();
            $url = '/en/attachment/'.$attachment->getId();

            // The default anonymous fixture can read parts, but not private files.
            $client->request('GET', $url.'/view');
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);

            $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
            $admin->setNeedPwChange(false);
            $em->flush();
            $client->loginUser($admin);
            foreach (['/view', '/download'] as $endpoint) {
                $client->request('GET', $url.$endpoint);
                self::assertResponseIsSuccessful();
                $response = $client->getResponse();
                self::assertInstanceOf(BinaryFileResponse::class, $response);
                self::assertSame('Private attachment test', file_get_contents($response->getFile()->getPathname()));
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            }
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
            $admin->getPermissions()->setPermissionValue('attachments', 'show_private', false);
            $em->flush();
            $client->loginUser($admin);
            $client->request('GET', $url.'/view');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $fs->remove(dirname($path));
        }
    }
}
