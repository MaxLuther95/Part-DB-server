<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\DatasheetBlockType;
use App\Entity\Production\DatasheetDocument;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\DatasheetTemplateRevision;
use App\Entity\Production\ProtocolRevisionStatus;
use App\Entity\UserSystem\User;
use App\Form\Production\DatasheetTemplateType;
use App\Repository\Production\BuildInstanceRepository;
use App\Repository\Production\DatasheetDocumentRepository;
use App\Repository\Production\DatasheetTemplateRepository;
use App\Services\Production\DatasheetDocumentStorage;
use App\Services\Production\DatasheetRenderer;
use App\Services\Production\DatasheetSourceCatalog;
use App\Services\Production\DatasheetTemplateEditor;
use App\Services\Production\DatasheetTemplateManager;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/production')]
final class DatasheetTemplateController extends AbstractController
{
    #[Route(path: '/datasheet-templates', name: 'production_datasheet_template_index', methods: ['GET'])]
    public function index(DatasheetTemplateRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheet_templates.read');

        return $this->render('production/datasheet_template/index.html.twig', [
            'templates' => $repository->findBy([], [
                'name' => 'ASC',
            ]),
        ]);
    }

    #[Route(path: '/datasheet-templates/new', name: 'production_datasheet_template_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, DatasheetTemplateManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheet_templates.create');
        $this->denyTemplateAdministration();
        $template = (new DatasheetTemplate())->setName('New data sheet')
            ->setProductTitle('Product Data Sheet');
        $form = $this->createForm(DatasheetTemplateType::class, $template);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $revision = $manager->createInitialDraft($template);
            $entityManager->persist($template);
            $entityManager->flush();
            $this->addFlash('success', 'Die leere Datenblattvorlage wurde als Entwurf angelegt.');

            return $this->redirectToRoute('production_datasheet_revision_edit', [
                'id' => $revision->getId(),
            ]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => 'Datenblattvorlage anlegen',
            'save_label' => 'production.datasheet.action.save_draft',
            'cancel_route' => 'production_datasheet_template_index',
        ]);
    }

    #[Route(path: '/datasheet-templates/{id}', name: 'production_datasheet_template_show', requirements: [
        'id' => '\\d+',
    ], methods: ['GET'])]
    public function show(DatasheetTemplate $template): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheet_templates.read');

        return $this->render('production/datasheet_template/show.html.twig', [
            'template' => $template,
        ]);
    }

    #[Route(path: '/datasheet-templates/{id}/edit', name: 'production_datasheet_template_edit', requirements: [
        'id' => '\\d+',
    ], methods: ['GET', 'POST'])]
    public function edit(DatasheetTemplate $template, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheet_templates.edit');
        $this->denyTemplateAdministration();
        $form = $this->createForm(DatasheetTemplateType::class, $template);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_datasheet_template_show', [
                'id' => $template->getId(),
            ]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => 'Datenblattvorlage bearbeiten',
            'save_label' => 'production.datasheet.action.save_draft',
            'cancel_route' => 'production_datasheet_template_show',
            'cancel_route_params' => [
                'id' => $template->getId(),
            ],
        ]);
    }

    #[Route(path: '/datasheet-templates/{id}/draft', name: 'production_datasheet_template_draft', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function draft(DatasheetTemplate $template, Request $request, EntityManagerInterface $entityManager, DatasheetTemplateManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheet_templates.edit');
        $this->denyTemplateAdministration();
        $this->assertCsrf('datasheet_template_draft_'.$template->getId(), $request);
        $revision = $manager->getOrCreateDraft($template);
        $entityManager->persist($revision);
        $entityManager->flush();

        return $this->redirectToRoute('production_datasheet_revision_edit', [
            'id' => $revision->getId(),
        ]);
    }

    #[Route(path: '/datasheet-revisions/{id}', name: 'production_datasheet_revision_edit', requirements: [
        'id' => '\\d+',
    ], methods: ['GET', 'POST'])]
    public function revision(
        DatasheetTemplateRevision $revision,
        Request $request,
        DatasheetTemplateEditor $editor,
        DatasheetSourceCatalog $sourceCatalog,
    ): Response {
        $this->denyAccessUnlessGranted('@production_datasheet_templates.edit');
        $this->denyTemplateAdministration();
        $this->assertDraft($revision);

        if ($request->isMethod('POST')) {
            $this->assertCsrf('datasheet_revision_edit_'.$revision->getId(), $request);
            try {
                $editor->save($revision, $request->request->getString('editor_payload'));
                $this->addFlash('success', 'Der Datenblattentwurf wurde vollständig gespeichert.');
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }

            return $this->redirectToRoute('production_datasheet_revision_edit', [
                'id' => $revision->getId(),
            ]);
        }

        return $this->render('production/datasheet_template/revision.html.twig', [
            'revision' => $revision,
            'editor_state' => $editor->export($revision),
            'source_catalog' => [
                'root' => $sourceCatalog->getRootChoices(),
                'child' => $sourceCatalog->getChildChoices(),
            ],
        ]);
    }

    #[Route(path: '/datasheet-revisions/{id}/publish', name: 'production_datasheet_revision_publish', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function publish(DatasheetTemplateRevision $revision, Request $request, EntityManagerInterface $entityManager, DatasheetTemplateManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheet_templates.publish');
        $this->denyTemplateAdministration();
        $this->assertDraft($revision);
        $this->assertCsrf('datasheet_revision_publish_'.$revision->getId(), $request);
        try {
            $manager->publish($revision, $this->currentUser());
            $entityManager->flush();
            $this->addFlash('success', 'Die Datenblattrevision wurde veröffentlicht.');

            return $this->redirectToRoute('production_datasheet_template_show', [
                'id' => $revision->getTemplate()?->getId(),
            ]);
        } catch (\DomainException $exception) {
            foreach (explode("\n", $exception->getMessage()) as $message) {
                $this->addFlash('error', $message);
            }

            return $this->redirectToRoute('production_datasheet_revision_edit', [
                'id' => $revision->getId(),
            ]);
        }
    }

    #[Route(path: '/datasheet-revisions/{id}/preview.pdf', name: 'production_datasheet_revision_pdf', requirements: [
        'id' => '\\d+',
    ], methods: ['GET'])]
    public function previewPdf(DatasheetTemplateRevision $revision, Request $request, BuildInstanceRepository $buildInstances, DatasheetRenderer $renderer): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheet_templates.read');
        if (ProtocolRevisionStatus::Draft === $revision->getStatus()) {
            $this->denyTemplateAdministration();
        }
        $instanceId = $request->query->getInt('build_instance');
        $instance = 0 < $instanceId ? $buildInstances->find($instanceId) : null;
        if (null !== $instance) {
            $this->denyAccessUnlessGranted('@production_build_instances.read');
        }
        $pdf = $renderer->renderPdf($revision, $instance, true);
        $filename = sprintf('datasheet-%d-v%d-preview.pdf', $revision->getTemplate()?->getId(), $revision->getRevisionNumber());

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route(path: '/build-instances/{instance}/datasheets/{template}/prepare', name: 'production_datasheet_prepare', requirements: [
        'instance' => '\\d+',
        'template' => '\\d+',
    ], methods: ['GET'])]
    public function prepare(BuildInstance $instance, DatasheetTemplate $template, DatasheetRenderer $renderer): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheets.create');
        $this->denyAccessUnlessGranted('@production_build_instances.read');
        $revision = $this->usablePublishedRevision($template);

        return $this->render('production/datasheet/prepare.html.twig', [
            'instance' => $instance,
            'template' => $template,
            'revision' => $revision,
            'datasheet_view' => $renderer->createView($revision, $instance, true),
            'run_choices' => $renderer->collectRunChoices($revision, $instance),
        ]);
    }

    #[Route(path: '/build-instances/{instance}/datasheets/{template}/preview.pdf', name: 'production_datasheet_instance_preview_pdf', requirements: [
        'instance' => '\\d+',
        'template' => '\\d+',
    ], methods: ['POST'])]
    public function instancePreviewPdf(BuildInstance $instance, DatasheetTemplate $template, Request $request, DatasheetRenderer $renderer): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheets.create');
        $this->denyAccessUnlessGranted('@production_build_instances.read');
        $this->assertCsrf('datasheet_preview_'.$instance->getId().'_'.$template->getId(), $request);
        $revision = $this->usablePublishedRevision($template);
        $notes = $this->submittedNotes($revision, $request);
        $runSelections = $this->submittedRunSelections($request);
        $additionalNotes = $this->submittedAdditionalNotes($request);
        $pdf = $renderer->renderPdf($revision, $instance, true, $notes, $runSelections, $additionalNotes);
        $safeSerial = preg_replace('/[^A-Za-z0-9._-]+/', '-', $instance->getDisplayIdentifier()) ?: 'instance';

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="datasheet-%s-preview.pdf"', $safeSerial),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route(path: '/build-instances/{instance}/datasheets/{template}/release', name: 'production_datasheet_release', requirements: [
        'instance' => '\\d+',
        'template' => '\\d+',
    ], methods: ['POST'])]
    public function release(
        BuildInstance $instance,
        DatasheetTemplate $template,
        Request $request,
        EntityManagerInterface $entityManager,
        DatasheetDocumentRepository $documentRepository,
        DatasheetRenderer $renderer,
        DatasheetDocumentStorage $storage,
    ): Response {
        $this->denyAccessUnlessGranted('@production_datasheets.create');
        $this->denyAccessUnlessGranted('@production_build_instances.read');
        $this->assertCsrf('datasheet_preview_'.$instance->getId().'_'.$template->getId(), $request);
        $revision = $this->usablePublishedRevision($template);
        $notes = $this->submittedNotes($revision, $request);
        $runSelections = $this->submittedRunSelections($request);
        $additionalNotes = $this->submittedAdditionalNotes($request);
        $view = $renderer->createView($revision, $instance, false, $notes, $runSelections, $additionalNotes);
        $errors = $renderer->validateForRelease($view);
        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('production_datasheet_prepare', [
                'instance' => $instance->getId(),
                'template' => $template->getId(),
            ]);
        }

        $pdf = $renderer->renderPdf($revision, $instance, false, $notes, $runSelections, $additionalNotes);
        $snapshot = $renderer->createSourceSnapshot($view, $runSelections);
        $document = null;
        try {
            $document = $entityManager->wrapInTransaction(function () use ($entityManager, $documentRepository, $storage, $pdf, $snapshot, $instance, $template, $revision, &$document): DatasheetDocument {
                if (! $entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
                    $entityManager->lock($instance, LockMode::PESSIMISTIC_WRITE);
                }
                $documentRevision = $documentRepository->getNextDocumentRevision($instance, $template);
                $filename = sprintf('%s-%s-R%d.pdf', $instance->getDisplayIdentifier(), $template->getName(), $documentRevision);
                $document = $storage->store($pdf, $filename, $snapshot)
                    ->setBuildInstance($instance)
                    ->setTemplate($template)
                    ->setRevision($revision)
                    ->setDocumentRevision($documentRevision)
                    ->setReleasedBy($this->currentUser());
                $entityManager->persist($document);
                $entityManager->flush();

                return $document;
            });
        } catch (\Throwable $exception) {
            if ($document instanceof DatasheetDocument) {
                $storage->remove($document);
            }
            throw $exception;
        }
        $this->addFlash('success', sprintf('Das Kundendatenblatt wurde unveränderlich als Revision %d freigegeben.', $document->getDocumentRevision()));

        return $this->redirectToRoute('production_build_instance_show', [
            'id' => $instance->getId(),
        ]);
    }

    #[Route(path: '/datasheets/{id}/download', name: 'production_datasheet_download', requirements: [
        'id' => '\\d+',
    ], methods: ['GET'])]
    public function download(DatasheetDocument $document, DatasheetDocumentStorage $storage): Response
    {
        $this->denyAccessUnlessGranted('@production_datasheets.read');

        return $storage->createDownloadResponse($document);
    }

    private function denyTemplateAdministration(): void
    {
        if (! $this->isGranted('@users.edit_permissions') && ! $this->isGranted('@groups.edit_permissions')) {
            throw $this->createAccessDeniedException('Only administrators may modify datasheet templates.');
        }
    }

    private function assertDraft(?DatasheetTemplateRevision $revision): void
    {
        if (! $revision instanceof DatasheetTemplateRevision || ProtocolRevisionStatus::Draft !== $revision->getStatus()) {
            throw $this->createNotFoundException('Only draft datasheet revisions can be edited.');
        }
    }

    private function assertCsrf(string $tokenId, Request $request): void
    {
        if (! $this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function usablePublishedRevision(DatasheetTemplate $template): DatasheetTemplateRevision
    {
        $revision = $template->getPublishedRevision();
        if (! $template->isActive() || ! $revision instanceof DatasheetTemplateRevision) {
            throw $this->createNotFoundException('The datasheet template is not active or has no published revision.');
        }

        return $revision;
    }

    /**
     * @return array<string, string>
     */
    private function submittedNotes(DatasheetTemplateRevision $revision, Request $request): array
    {
        $notes = [];
        $submittedNotes = $request->request->all('notes');
        foreach ($revision->getBlocks() as $block) {
            if (DatasheetBlockType::EditableNote !== $block->getType()) {
                continue;
            }
            $value = $submittedNotes[$block->getStableKey()] ?? $block->getText() ?? '';
            $notes[$block->getStableKey()] = mb_substr(is_string($value) ? trim($value) : '', 0, 10000);
        }

        return $notes;
    }

    /**
     * @return array<string, int>
     */
    private function submittedRunSelections(Request $request): array
    {
        $selections = [];
        foreach ($request->request->all('runs') as $key => $value) {
            if (! is_string($key) || 1 !== preg_match('/^\d+_\d+$/D', $key) || (! is_int($value) && ! is_string($value))) {
                continue;
            }
            $runId = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                ],
            ]);
            if (false !== $runId) {
                $selections[$key] = $runId;
            }
        }

        return $selections;
    }

    /**
     * @return list<array{title: string, text: string, width: int}>
     */
    private function submittedAdditionalNotes(Request $request): array
    {
        $notes = [];
        foreach (array_slice($request->request->all('additional_notes'), 0, 20) as $submittedNote) {
            if (! is_array($submittedNote)) {
                continue;
            }
            $title = mb_substr(trim(is_string($submittedNote['title'] ?? null) ? $submittedNote['title'] : ''), 0, 255);
            $text = mb_substr(trim(is_string($submittedNote['text'] ?? null) ? $submittedNote['text'] : ''), 0, 10000);
            if ('' === $title && '' === $text) {
                continue;
            }
            $width = filter_var($submittedNote['width'] ?? 12, FILTER_VALIDATE_INT);
            $notes[] = [
                'title' => '' === $title ? 'Additional information' : $title,
                'text' => $text,
                'width' => in_array($width, [3, 6, 9, 12], true) ? $width : 12,
            ];
        }

        return $notes;
    }
}
