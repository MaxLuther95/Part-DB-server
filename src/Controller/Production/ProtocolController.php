<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\ProtocolRevisionStatus;
use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolRunStatus;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use App\Entity\UserSystem\User;
use App\Form\Production\ProtocolRunType;
use App\Form\Production\ProtocolTemplateFieldType;
use App\Form\Production\ProtocolTemplateSectionType;
use App\Form\Production\ProtocolTemplateType;
use App\Repository\Production\ProtocolTemplateRepository;
use App\Services\Production\ProtocolManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/production')]
final class ProtocolController extends AbstractController
{
    #[Route(path: '/protocol-templates', name: 'production_protocol_template_index', methods: ['GET'])]
    public function templateIndex(ProtocolTemplateRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.read');

        return $this->render('production/protocol_template/index.html.twig', [
            'templates' => $repository->findBy([], [
                'name' => 'ASC',
            ]),
        ]);
    }

    #[Route(path: '/protocol-templates/new', name: 'production_protocol_template_new', methods: ['GET', 'POST'])]
    public function templateNew(Request $request, EntityManagerInterface $entityManager, ProtocolManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.create');
        $this->denyTemplateAdministration();
        $template = new ProtocolTemplate();
        $form = $this->createForm(ProtocolTemplateType::class, $template);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->createInitialDraft($template);
            $entityManager->persist($template);
            $entityManager->flush();
            $this->addFlash('success', 'Die Laufzettelvorlage wurde angelegt. Ergänze jetzt Abschnitte und Felder.');

            return $this->redirectToRoute('production_protocol_revision_edit', [
                'id' => $template->getDraftRevision()?->getId(),
            ]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => 'production.protocol.template.new',
            'save_label' => 'production.protocol.template.save_draft',
            'cancel_route' => 'production_protocol_template_index',
        ]);
    }

    #[Route(path: '/protocol-templates/{id}', name: 'production_protocol_template_show', requirements: [
        'id' => '\\d+',
    ], methods: ['GET'])]
    public function templateShow(ProtocolTemplate $template): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.read');

        return $this->render('production/protocol_template/show.html.twig', [
            'template' => $template,
        ]);
    }

    #[Route(path: '/protocol-templates/{id}/edit', name: 'production_protocol_template_edit', requirements: [
        'id' => '\\d+',
    ], methods: ['GET', 'POST'])]
    public function templateEdit(ProtocolTemplate $template, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $form = $this->createForm(ProtocolTemplateType::class, $template);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_protocol_template_show', [
                'id' => $template->getId(),
            ]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => 'production.protocol.template.edit',
            'save_label' => 'production.protocol.template.save_draft',
            'cancel_route' => 'production_protocol_template_show',
            'cancel_route_params' => [
                'id' => $template->getId(),
            ],
        ]);
    }

    #[Route(path: '/protocol-templates/{id}/draft', name: 'production_protocol_template_draft', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function createDraft(ProtocolTemplate $template, Request $request, EntityManagerInterface $entityManager, ProtocolManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $this->assertCsrf('protocol_template_draft_'.$template->getId(), $request);
        $revision = $manager->getOrCreateDraft($template);
        $entityManager->persist($revision);
        $entityManager->flush();

        return $this->redirectToRoute('production_protocol_revision_edit', [
            'id' => $revision->getId(),
        ]);
    }

    #[Route(path: '/protocol-revisions/{id}', name: 'production_protocol_revision_edit', requirements: [
        'id' => '\\d+',
    ], methods: ['GET'])]
    public function revisionEdit(ProtocolTemplateRevision $revision): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $this->assertDraft($revision);

        return $this->render('production/protocol_template/revision.html.twig', [
            'revision' => $revision,
        ]);
    }

    #[Route(path: '/protocol-revisions/{id}/save', name: 'production_protocol_revision_save', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function revisionSave(ProtocolTemplateRevision $revision, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $this->assertDraft($revision);
        $this->assertCsrf('protocol_revision_save_'.$revision->getId(), $request);
        $entityManager->flush();
        $this->addFlash('success', 'production.protocol.template.draft_saved');

        return $this->redirectToRoute('production_protocol_template_show', [
            'id' => $revision->getTemplate()?->getId(),
        ]);
    }

    #[Route(path: '/protocol-revisions/{id}/publish', name: 'production_protocol_revision_publish', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function publish(ProtocolTemplateRevision $revision, Request $request, EntityManagerInterface $entityManager, ProtocolManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.publish');
        $this->denyTemplateAdministration();
        $this->assertCsrf('protocol_revision_publish_'.$revision->getId(), $request);
        try {
            $manager->publish($revision, $this->currentUser());
            $entityManager->flush();
            $this->addFlash('success', 'Die Revision wurde veröffentlicht und kann nun für neue Laufzettel verwendet werden.');

            return $this->redirectToRoute('production_protocol_template_show', [
                'id' => $revision->getTemplate()?->getId(),
            ]);
        } catch (\DomainException $exception) {
            foreach (explode("\n", $exception->getMessage()) as $message) {
                $this->addFlash('error', $message);
            }

            return $this->redirectToRoute('production_protocol_revision_edit', [
                'id' => $revision->getId(),
            ]);
        }
    }

    #[Route(path: '/protocol-revisions/{id}/sections/new', name: 'production_protocol_section_new', requirements: [
        'id' => '\\d+',
    ], methods: ['GET', 'POST'])]
    public function sectionNew(ProtocolTemplateRevision $revision, Request $request, EntityManagerInterface $entityManager, ProtocolManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $this->assertDraft($revision);
        $section = $manager->addSection($revision);

        return $this->handleSectionForm($section, $request, $entityManager, true);
    }

    #[Route(path: '/protocol-sections/{id}/edit', name: 'production_protocol_section_edit', requirements: [
        'id' => '\\d+',
    ], methods: ['GET', 'POST'])]
    public function sectionEdit(ProtocolTemplateSection $section, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $this->assertDraft($section->getRevision());

        return $this->handleSectionForm($section, $request, $entityManager, false);
    }

    #[Route(path: '/protocol-sections/{id}/delete', name: 'production_protocol_section_delete', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function sectionDelete(ProtocolTemplateSection $section, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $revision = $section->getRevision() ?? throw $this->createNotFoundException();
        $this->assertDraft($revision);
        $this->assertCsrf('protocol_section_delete_'.$section->getId(), $request);
        $revision->removeSection($section);
        $entityManager->flush();
        $this->addFlash('success', 'Der Abschnitt wurde aus dem Entwurf entfernt.');

        return $this->redirectToRoute('production_protocol_revision_edit', [
            'id' => $revision->getId(),
        ]);
    }

    #[Route(path: '/protocol-sections/{id}/move/{direction}', name: 'production_protocol_section_move', requirements: [
        'id' => '\\d+',
        'direction' => 'up|down',
    ], methods: ['POST'])]
    public function sectionMove(ProtocolTemplateSection $section, string $direction, Request $request, ProtocolManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $revision = $section->getRevision();
        $this->assertDraft($revision);
        $this->assertCsrf('protocol_section_move_'.$section->getId(), $request);
        $manager->moveSection($section, 'up' === $direction ? -1 : 1);

        return $this->redirectToRoute('production_protocol_revision_edit', [
            'id' => $revision?->getId(),
        ]);
    }

    #[Route(path: '/protocol-sections/{id}/fields/new', name: 'production_protocol_field_new', requirements: [
        'id' => '\\d+',
    ], methods: ['GET', 'POST'])]
    public function fieldNew(ProtocolTemplateSection $section, Request $request, EntityManagerInterface $entityManager, ProtocolManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $this->assertDraft($section->getRevision());
        $field = $manager->addField($section);

        return $this->handleFieldForm($field, $request, $entityManager, true);
    }

    #[Route(path: '/protocol-fields/{id}/edit', name: 'production_protocol_field_edit', requirements: [
        'id' => '\\d+',
    ], methods: ['GET', 'POST'])]
    public function fieldEdit(ProtocolTemplateField $field, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $this->assertDraft($field->getSection()?->getRevision());

        return $this->handleFieldForm($field, $request, $entityManager, false);
    }

    #[Route(path: '/protocol-fields/{id}/delete', name: 'production_protocol_field_delete', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function fieldDelete(ProtocolTemplateField $field, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $section = $field->getSection() ?? throw $this->createNotFoundException();
        $revision = $section->getRevision();
        $this->assertDraft($revision);
        $this->assertCsrf('protocol_field_delete_'.$field->getId(), $request);
        $section->removeField($field);
        $entityManager->flush();
        $this->addFlash('success', 'Das Feld wurde aus dem Entwurf entfernt.');

        return $this->redirectToRoute('production_protocol_revision_edit', [
            'id' => $revision?->getId(),
        ]);
    }

    #[Route(path: '/protocol-fields/{id}/move/{direction}', name: 'production_protocol_field_move', requirements: [
        'id' => '\\d+',
        'direction' => 'up|down',
    ], methods: ['POST'])]
    public function fieldMove(ProtocolTemplateField $field, string $direction, Request $request, ProtocolManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $revision = $field->getSection()?->getRevision();
        $this->assertDraft($revision);
        $this->assertCsrf('protocol_field_move_'.$field->getId(), $request);
        $manager->moveField($field, 'up' === $direction ? -1 : 1);

        return $this->redirectToRoute('production_protocol_revision_edit', [
            'id' => $revision?->getId(),
        ]);
    }

    #[Route(path: '/build-instances/{id}/protocol-runs', name: 'production_protocol_run_new', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function runNew(BuildInstance $buildInstance, Request $request, ProtocolTemplateRepository $repository, ProtocolManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocols.create');
        $this->assertCsrf('protocol_run_new_'.$buildInstance->getId(), $request);
        $template = $repository->find($request->request->getInt('template_id'));
        $revision = $template?->getPublishedRevision();
        if (! $template instanceof ProtocolTemplate || ! $template->isActive() || ! $revision instanceof ProtocolTemplateRevision) {
            $this->addFlash('error', 'Bitte eine aktive, veröffentlichte Laufzettelvorlage auswählen.');

            return $this->redirectToRoute('production_build_instance_show', [
                'id' => $buildInstance->getId(),
            ]);
        }
        $run = $manager->createRun($buildInstance, $revision, $this->currentUser());
        $this->addFlash('success', sprintf('Laufzettel %s #%d wurde als Entwurf angelegt.', $template->getName(), $run->getRunNumber()));

        return $this->redirectToRoute('production_protocol_run_edit', [
            'id' => $run->getId(),
        ]);
    }

    #[Route(path: '/protocol-runs/{id}', name: 'production_protocol_run_show', requirements: [
        'id' => '\\d+',
    ], methods: ['GET'])]
    public function runShow(ProtocolRun $run): Response
    {
        $this->denyAccessUnlessGranted('@production_protocols.read');

        return $this->render('production/protocol_run/show.html.twig', [
            'run' => $run,
            'form' => null,
        ]);
    }

    #[Route(path: '/protocol-runs/{id}/edit', name: 'production_protocol_run_edit', requirements: [
        'id' => '\\d+',
    ], methods: ['GET', 'POST'])]
    public function runEdit(ProtocolRun $run, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocols.edit');
        if (ProtocolRunStatus::Draft !== $run->getStatus()) {
            return $this->redirectToRoute('production_protocol_run_show', [
                'id' => $run->getId(),
            ]);
        }
        $form = $this->createForm(ProtocolRunType::class, null, [
            'protocol_run' => $run,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $run->touch($this->currentUser());
            $entityManager->flush();
            $this->addFlash('success', 'Der Laufzettelentwurf wurde gespeichert und bleibt bearbeitbar.');
            $buildInstance = $run->getBuildInstance() ?? throw $this->createNotFoundException('This protocol run has no build instance.');

            return $this->redirectToRoute('production_build_instance_show', [
                'id' => $buildInstance->getId(),
            ]);
        }

        return $this->render('production/protocol_run/show.html.twig', [
            'run' => $run,
            'form' => $form,
        ]);
    }

    #[Route(path: '/protocol-runs/{id}/complete', name: 'production_protocol_run_complete', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function runComplete(ProtocolRun $run, Request $request, EntityManagerInterface $entityManager, ProtocolManager $manager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocols.complete');
        $this->assertCsrf('protocol_run_complete_'.$run->getId(), $request);
        if (ProtocolRunStatus::Draft !== $run->getStatus()) {
            $this->addFlash('info', 'Dieser Laufzettel ist bereits abgeschlossen und wurde nicht verändert.');

            return $this->redirectToRoute('production_protocol_run_show', [
                'id' => $run->getId(),
            ]);
        }
        $errors = $manager->validateForCompletion($run);
        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('production_protocol_run_edit', [
                'id' => $run->getId(),
            ]);
        }
        $run->complete($this->currentUser());
        $entityManager->flush();
        $this->addFlash('success', 'Der Laufzettel wurde fertiggestellt. Seine Werte sind jetzt unveränderlich.');

        return $this->redirectToRoute('production_protocol_run_show', [
            'id' => $run->getId(),
        ]);
    }

    #[Route(path: '/protocol-runs/{id}/invalidate', name: 'production_protocol_run_invalidate', requirements: [
        'id' => '\\d+',
    ], methods: ['POST'])]
    public function runInvalidate(ProtocolRun $run, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_protocols.invalidate');
        $this->assertCsrf('protocol_run_invalidate_'.$run->getId(), $request);
        $reason = mb_substr(trim($request->request->getString('reason')), 0, 2000);
        try {
            $run->invalidate($reason, $this->currentUser());
            $entityManager->flush();
            $this->addFlash('warning', 'Der Laufzettel wurde als ungültig markiert. Die historischen Werte bleiben erhalten.');
        } catch (\InvalidArgumentException|\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('production_protocol_run_show', [
            'id' => $run->getId(),
        ]);
    }

    private function handleSectionForm(ProtocolTemplateSection $section, Request $request, EntityManagerInterface $entityManager, bool $isNew): Response
    {
        $form = $this->createForm(ProtocolTemplateSectionType::class, $section);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($section);
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_protocol_revision_edit', [
                'id' => $section->getRevision()?->getId(),
            ]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => $isNew ? 'production.protocol.section.new' : 'production.protocol.section.edit',
            'save_label' => 'production.protocol.template.save_draft',
            'cancel_route' => 'production_protocol_revision_edit',
            'cancel_route_params' => [
                'id' => $section->getRevision()?->getId(),
            ],
        ]);
    }

    private function handleFieldForm(ProtocolTemplateField $field, Request $request, EntityManagerInterface $entityManager, bool $isNew): Response
    {
        $form = $this->createForm(ProtocolTemplateFieldType::class, $field);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($field);
            $entityManager->flush();
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_protocol_revision_edit', [
                'id' => $field->getSection()?->getRevision()?->getId(),
            ]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => $isNew ? 'production.protocol.field.new' : 'production.protocol.field.edit',
            'save_label' => 'production.protocol.template.save_draft',
            'cancel_route' => 'production_protocol_revision_edit',
            'cancel_route_params' => [
                'id' => $field->getSection()?->getRevision()?->getId(),
            ],
        ]);
    }

    private function denyTemplateAdministration(): void
    {
        if (! $this->isGranted('@users.edit_permissions') && ! $this->isGranted('@groups.edit_permissions')) {
            throw $this->createAccessDeniedException('Only administrators may modify protocol templates.');
        }
    }

    private function assertDraft(?ProtocolTemplateRevision $revision): void
    {
        if (! $revision instanceof ProtocolTemplateRevision || ProtocolRevisionStatus::Draft !== $revision->getStatus()) {
            throw $this->createNotFoundException('Only draft revisions can be edited.');
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
}
