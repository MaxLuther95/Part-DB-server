<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildInstanceAttachment;
use App\Entity\UserSystem\User;
use App\Services\Production\BuildInstanceAttachmentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/production')]
final class BuildInstanceAttachmentController extends AbstractController
{
    #[Route(path: '/build-instances/{id}/attachments', name: 'production_build_instance_attachment_upload', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function upload(BuildInstance $buildInstance, Request $request, EntityManagerInterface $entityManager, BuildInstanceAttachmentStorage $storage, LoggerInterface $logger): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.edit');
        $this->assertCsrf('build_instance_attachment_'.$buildInstance->getId(), $request);
        $this->denyAccessUnlessGranted('read', $buildInstance);
        $file = $request->files->get('attachment');
        if (! $file instanceof UploadedFile) {
            $this->addFlash('error', 'Bitte eine gültige Datei auswählen.');

            return $this->redirect($this->showUrl($buildInstance));
        }

        $attachment = null;
        try {
            $currentSize = array_sum(array_map(static fn (BuildInstanceAttachment $item): int => $item->getFileSize(), $buildInstance->getAttachments()->toArray()));
            if ($buildInstance->getAttachments()->count() >= BuildInstanceAttachmentStorage::MAX_ATTACHMENTS_PER_INSTANCE) {
                throw new \InvalidArgumentException('Für diese Instanz ist die maximale Anzahl von 100 Dateianhängen erreicht.');
            }
            if ($currentSize + (int) $file->getSize() > BuildInstanceAttachmentStorage::MAX_TOTAL_SIZE_PER_INSTANCE) {
                throw new \InvalidArgumentException('Die Dateianhänge dieser Instanz dürfen zusammen höchstens 250 MB belegen.');
            }
            $attachment = $storage->storeUpload($buildInstance, $file);
            $attachment
                ->setCategory($request->request->getString('category'))
                ->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null);
            $entityManager->persist($attachment);
            $entityManager->flush();
            $this->addFlash('success', 'Der Dateianhang wurde an der gebauten Instanz gespeichert.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            if ($attachment instanceof BuildInstanceAttachment) {
                $storage->remove($attachment);
            }
            $logger->error('A build instance attachment could not be stored.', [
                'build_instance_id' => $buildInstance->getId(),
                'exception' => $exception,
            ]);
            $this->addFlash('error', 'Der Dateianhang konnte nicht gespeichert werden. Details stehen ausschließlich im Serverprotokoll.');
        }

        return $this->redirect($this->showUrl($buildInstance));
    }

    #[Route(path: '/build-instance-attachments/{id}/download', name: 'production_build_instance_attachment_download', requirements: [
        'id' => '\\d+',
    ], methods: ['GET'])]
    public function download(BuildInstanceAttachment $attachment, BuildInstanceAttachmentStorage $storage): Response
    {
        $this->denyAccessUnlessGranted('read', $attachment->getBuildInstance() ?? throw $this->createNotFoundException());
        try {
            $path = $storage->getAbsolutePath($attachment);
        } catch (\RuntimeException) {
            throw $this->createNotFoundException('Die Datei ist nicht mehr vorhanden.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Download-Options', 'noopen');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getOriginalFilename());

        return $response;
    }

    #[Route(path: '/build-instance-attachments/{id}/delete', name: 'production_build_instance_attachment_delete', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function delete(BuildInstanceAttachment $attachment, Request $request, EntityManagerInterface $entityManager, BuildInstanceAttachmentStorage $storage): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.edit');
        $this->assertCsrf('delete_build_instance_attachment_'.$attachment->getId(), $request);
        $buildInstance = $attachment->getBuildInstance() ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted('read', $buildInstance);
        $entityManager->remove($attachment);
        $entityManager->flush();
        $storage->remove($attachment);
        $this->addFlash('success', 'Der Dateianhang wurde gelöscht.');

        return $this->redirect($this->showUrl($buildInstance));
    }

    private function showUrl(BuildInstance $buildInstance): string
    {
        return $this->generateUrl('production_build_instance_show', [
            'id' => $buildInstance->getId(),
        ]).'#attachments';
    }

    private function assertCsrf(string $tokenId, Request $request): void
    {
        if (! $this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
