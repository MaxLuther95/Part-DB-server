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
use App\Services\Production\IncompleteReleaseConfirmation;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Form\FormInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        $form = $this->createForm(ProtocolTemplateType::class, $template, [
            'allow_system_assignment' => $this->isGranted('@production_system_templates.read'),
            'allow_project_assignment' => $this->isGranted('@projects.read'),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->createInitialDraft($template);
            $entityManager->persist($template);
            try {
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Die Zuordnung wurde zwischenzeitlich geändert. Es wurde nichts gespeichert. Bitte prüfe die Zuordnungen erneut.');

                return $this->redirectToRoute('production_protocol_template_new');
            }
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
    public function templateEdit(ProtocolTemplate $template, Request $request, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.edit');
        $this->denyTemplateAdministration();
        $form = $this->createForm(ProtocolTemplateType::class, $template, [
            'allow_system_assignment' => $this->isGranted('@production_system_templates.read'),
            'allow_project_assignment' => $this->isGranted('@projects.read'),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Die Zuordnung wurde zwischenzeitlich geändert. Es wurde nichts gespeichert. Bitte prüfe die Zuordnungen erneut.');

                return $this->redirectToRoute('production_protocol_template_edit', ['id' => $template->getId()]);
            }
            $this->addFlash('success', 'production.flash.saved');

            return $this->redirectToRoute('production_protocol_template_show', [
                'id' => $template->getId(),
            ]);
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => 'production.protocol.template.edit',
            'delete_route' => 'production_protocol_template_delete',
            'delete_route_params' => ['id' => $template->getId()],
            'delete_permission' => '@production_protocol_templates.delete',
            'delete_token_id' => 'protocol_template_delete_'.$template->getId(),
            'delete_confirm' => $translator->trans('production.protocol.template.delete_confirm', ['%name%' => $template->getName()], 'production'),
            'save_label' => 'production.protocol.template.save_draft',
            'cancel_route' => 'production_protocol_template_show',
            'cancel_route_params' => [
                'id' => $template->getId(),
            ],
        ]);
    }

    #[Route(path: '/protocol-templates/{id}/delete', name: 'production_protocol_template_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function templateDelete(ProtocolTemplate $template, Request $request, ProtocolManager $manager, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.delete');
        $this->denyTemplateAdministration();
        $this->assertCsrf('protocol_template_delete_'.$template->getId(), $request);
        $id = $template->getId();
        try {
            $manager->deleteTemplate($template);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $translator->trans($exception->getMessage(), [], 'production'));

            return $this->redirectToRoute('production_protocol_template_show', ['id' => $id]);
        }
        $this->addFlash('success', $translator->trans('production.protocol.template.deleted', [], 'production'));

        return $this->redirectToRoute('production_protocol_template_index');
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
        $this->denyAccessUnlessGranted('read', $buildInstance);
        $template = $repository->findForInstance($buildInstance);
        $revision = $template?->getPublishedRevision();
        if (! $template instanceof ProtocolTemplate || ! $template->isActive() || ! $revision instanceof ProtocolTemplateRevision) {
            $this->addFlash('error', 'Diesem Bautyp ist keine aktive, veröffentlichte Laufzettelvorlage zugeordnet.');

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
        $this->denyAccessUnlessGranted('read', $run->getBuildInstance() ?? throw $this->createNotFoundException());

        return $this->render('production/protocol_run/show.html.twig', [
            'run' => $run,
            'form' => null,
        ]);
    }

    #[Route(path: '/protocol-runs/{id}/edit', name: 'production_protocol_run_edit', requirements: [
        'id' => '\\d+',
    ], methods: ['GET', 'POST'])]
    public function runEdit(ProtocolRun $run, Request $request, EntityManagerInterface $entityManager, ProtocolManager $manager, IncompleteReleaseConfirmation $confirmation): Response
    {
        $this->denyAccessUnlessGranted('@production_protocols.edit');
        $this->denyAccessUnlessGranted('read', $run->getBuildInstance() ?? throw $this->createNotFoundException());
        if (! $request->isMethod('POST') && ProtocolRunStatus::Draft !== $run->getStatus()) {
            return $this->redirectToRoute('production_protocol_run_show', [
                'id' => $run->getId(),
            ]);
        }
        $form = $this->createForm(ProtocolRunType::class, null, [
            'protocol_run' => $run,
        ]);
        $complete = 'complete' === $request->request->get('_action');
        if ($complete) {
            $this->denyAccessUnlessGranted('@production_protocols.complete');
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && (ProtocolRunStatus::Draft !== $run->getStatus()
            || $form->get('edit_version')->getData() !== (string) $run->getVersion())) {
            return $this->renderRunConflict($this->runConflictContext($run, $form));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $acceptedWarnings = [];
            if ($complete) {
                $warningContext = $this->completionWarningContext($run, $manager, $confirmation);
                if ([] !== $warningContext['incomplete_warnings'] && ! $confirmation->isAccepted($request, $warningContext['incomplete_confirmation'])) {
                    return $this->render('production/protocol_run/show.html.twig', [
                        'run' => $run,
                        'form' => $form,
                        ...$warningContext,
                    ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
                }
                $acceptedWarnings = $warningContext['incomplete_warnings'];
            }
            // Materialize the submitted form before flush: an optimistic conflict closes
            // the entity manager, so recovery must not depend on lazy entity relations.
            $conflict = $this->runConflictContext($run, $form);
            try {
                if ($complete) {
                    $run->complete($this->currentUser(), $acceptedWarnings);
                } else {
                    $run->touch($this->currentUser());
                }
                $entityManager->flush();
            } catch (OptimisticLockException) {
                return $this->renderRunConflict($conflict);
            }
            if ($complete) {
                $this->addFlash('success', 'Der Laufzettel wurde fertiggestellt. Seine Werte sind jetzt unveränderlich.');

                return $this->redirectToRoute('production_protocol_run_show', ['id' => $run->getId()]);
            }
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
    public function runComplete(ProtocolRun $run, Request $request, EntityManagerInterface $entityManager, ProtocolManager $manager, IncompleteReleaseConfirmation $confirmation): Response
    {
        $this->denyAccessUnlessGranted('@production_protocols.complete');
        $this->assertCsrf('protocol_run_complete_'.$run->getId(), $request);
        $this->denyAccessUnlessGranted('read', $run->getBuildInstance() ?? throw $this->createNotFoundException());
        $conflict = $this->runConflictContext($run);
        if ($request->request->getString('_version') !== (string) $run->getVersion()) {
            return $this->renderRunConflict($conflict);
        }
        if (ProtocolRunStatus::Draft !== $run->getStatus()) {
            $this->addFlash('info', 'Dieser Laufzettel ist bereits abgeschlossen und wurde nicht verändert.');

            return $this->redirectToRoute('production_protocol_run_show', [
                'id' => $run->getId(),
            ]);
        }
        $warningContext = $this->completionWarningContext($run, $manager, $confirmation);
        if ([] !== $warningContext['incomplete_warnings'] && ! $confirmation->isAccepted($request, $warningContext['incomplete_confirmation'])) {
            $form = $this->createForm(ProtocolRunType::class, null, [
                'protocol_run' => $run,
                'action' => $this->generateUrl('production_protocol_run_edit', ['id' => $run->getId()]),
            ]);

            return $this->render('production/protocol_run/show.html.twig', [
                'run' => $run, 'form' => $form, ...$warningContext,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        try {
            $run->complete($this->currentUser(), $warningContext['incomplete_warnings']);
            $entityManager->flush();
        } catch (OptimisticLockException) {
            return $this->renderRunConflict($conflict);
        }
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
        $this->denyAccessUnlessGranted('read', $run->getBuildInstance() ?? throw $this->createNotFoundException());
        $this->assertCsrf('protocol_run_invalidate_'.$run->getId(), $request);
        $reason = mb_substr(trim($request->request->getString('reason')), 0, 2000);
        $conflict = $this->runConflictContext($run, reason: $reason);
        if ($request->request->getString('_version') !== (string) $run->getVersion()) {
            return $this->renderRunConflict($conflict);
        }
        try {
            $run->invalidate($reason, $this->currentUser());
            $entityManager->flush();
            $this->addFlash('warning', 'Der Laufzettel wurde als ungültig markiert. Die historischen Werte bleiben erhalten.');
        } catch (OptimisticLockException) {
            return $this->renderRunConflict($conflict);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('production_protocol_run_show', [
            'id' => $run->getId(),
        ]);
    }

    /** @return array{incomplete_warnings: list<string>, incomplete_confirmation: string} */
    private function completionWarningContext(ProtocolRun $run, ProtocolManager $manager, IncompleteReleaseConfirmation $confirmation): array
    {
        $warnings = $manager->validateForCompletion($run);
        $answers = [];
        foreach ($run->getRows() as $row) {
            foreach ($row->getAnswers() as $answer) {
                $answers[$answer->getId()] = $answer->getValue();
            }
        }
        $tokenId = $confirmation->tokenId('protocol_'.$run->getId(), [
            'version' => $run->getVersion(),
            'date' => $run->getProtocolDate()?->format('Y-m-d'),
            'notes' => $run->getNotes(),
            'answers' => $answers,
            'warnings' => $warnings,
        ]);

        return ['incomplete_warnings' => $warnings, 'incomplete_confirmation' => $tokenId];
    }

    /** @return array<string, mixed> */
    private function runConflictContext(ProtocolRun $run, ?FormInterface $form = null, ?string $reason = null): array
    {
        $view = $form?->createView();
        if (null !== $view) {
            foreach ($run->getRows() as $row) {
                foreach ($row->getAnswers() as $answer) {
                    $name = 'answer_'.$answer->getId();
                    if (isset($view->children[$name])) {
                        $view->children[$name]->vars['label'] = $row->getSection()?->getName().' / '.$answer->getField()?->getLabel();
                        $view->children[$name]->vars['translation_domain'] = false;
                    }
                }
            }
        }

        return ['run_id' => $run->getId(), 'run_title' => (string) $run, 'form' => $view, 'reason' => $reason];
    }

    /** @param array<string, mixed> $context */
    private function renderRunConflict(array $context): Response
    {
        return $this->render('production/protocol_run/conflict.html.twig', $context, new Response(status: Response::HTTP_CONFLICT));
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
