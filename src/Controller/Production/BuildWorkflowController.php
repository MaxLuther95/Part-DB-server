<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Parts\StorageLocation;
use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\SystemTemplate;
use App\Entity\UserSystem\User;
use App\Services\Production\ProductionBuildWorkflow;
use App\Services\Production\StaleBuildDraftException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/production/build/process/{token}', requirements: ['token' => '[a-f0-9]{32}'])]
final class BuildWorkflowController extends AbstractController
{
    #[Route(path: '/next', name: 'production_build_workflow_next', methods: ['GET'])]
    public function next(string $token, Request $request, ProductionBuildWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.build');
        $draft = $this->draft($request, $token);
        if (null !== $changed = $this->changedDraftResponse($draft, $workflow)) {
            return $changed;
        }
        $next = $workflow->getNextUnconfiguredNode($draft);

        return null !== $next
            ? $this->redirectToRoute('production_build_workflow_configure', ['token' => $token, 'node' => $next])
            : $this->redirectToRoute('production_build_workflow_details', ['token' => $token]);
    }

    #[Route(path: '/configure/{node}', name: 'production_build_workflow_configure', requirements: ['node' => 'n\d+'], methods: ['GET', 'POST'])]
    public function configure(string $token, string $node, Request $request, ProductionBuildWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.build');
        $draft = $this->draft($request, $token);
        if (null !== $changed = $this->changedDraftResponse($draft, $workflow)) {
            return $changed;
        }
        $next = $workflow->getNextUnconfiguredNode($draft);
        if ($next !== $node) {
            return $this->redirectToRoute('production_build_workflow_next', ['token' => $token]);
        }
        $resolved = $workflow->resolveNode($draft, $node);
        if (!$resolved['content'] instanceof SystemTemplate) {
            throw $this->createNotFoundException();
        }
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('build_configure_'.$token.'_'.$node, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $errors = $workflow->configureNode($draft, $node, $request->request->all('slots'));
            if ([] === $errors) {
                $this->saveDraft($request, $token, $draft);
                return $this->redirectToRoute('production_build_workflow_next', ['token' => $token]);
            }
        }
        $slots = [];
        foreach ($resolved['content']->getSlots() as $slot) {
            $slots[] = ['slot' => $slot, 'choices' => $workflow->getChoices($slot)];
        }

        return $this->render('production/build_workflow/configure.html.twig', ['token' => $token, 'node' => $node, 'draft' => $draft, 'template' => $resolved['content'], 'slots' => $slots, 'errors' => $errors]);
    }

    #[Route(path: '/details', name: 'production_build_workflow_details', methods: ['GET', 'POST'])]
    public function details(string $token, Request $request, EntityManagerInterface $entityManager, ProductionBuildWorkflow $workflow, \App\Services\Production\SerialNumberManager $numbers): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.build');
        $draft = $this->draft($request, $token);
        if (null !== $changed = $this->changedDraftResponse($draft, $workflow)) {
            return $changed;
        }
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('build_details_'.$token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid CSRF token.'); }
            $siteId = $request->request->getInt('site_id');
            $details = $request->request->all('details');
            $errors = [];
            $serials = [];
            $selectedSite = $entityManager->find(StorageLocation::class, $siteId);
            if (!$selectedSite instanceof StorageLocation || null !== $selectedSite->getParent()) { $errors[] = 'Bitte einen gültigen Fertigungsstandort wählen.'; }
            foreach ($draft['nodes'] as $key => $node) {
                $submitted = $details[$key] ?? null;
                if (!is_array($submitted) || !is_string($submitted['prefix'] ?? '') || !is_string($submitted['number'] ?? '') || !is_string($submitted['notes'] ?? '')) {
                    $errors[] = $node['name'].': Ungültige Geräteangaben.';
                    continue;
                }
                $prefix = trim((string) ($details[$key]['prefix'] ?? ''));
                $number = trim((string) ($details[$key]['number'] ?? ''));
                try { $serial = $numbers->combine($prefix, $number) ?? ''; }
                catch (\RuntimeException $error) { $errors[] = $node['name'].': '.$error->getMessage(); $serial = ''; }
                $confirmed = '1' === ($details[$key]['confirmed'] ?? null);
                if ('' !== $serial && !$confirmed) { $errors[] = $node['name'].': Bitte die Seriennummer prüfen und bestätigen.'; }
                try { $numbers->validate($workflow->resolveNode($draft, (string) $key)['content'], '' === $serial ? null : $serial); }
                catch (\RuntimeException $error) { $errors[] = $node['name'].': '.$error->getMessage(); }
                $notes = trim((string) ($details[$key]['notes'] ?? ''));
                if ('' === $serial && '' === $notes) { $errors[] = sprintf('%s: Ohne Seriennummer muss in den Notizen ein Grund stehen.', $node['name']); }
                if ('' !== $serial) {
                    if (isset($serials[$serial]) || null !== $entityManager->getRepository(BuildInstance::class)->findOneBy(['serialNumber' => $serial])) { $errors[] = sprintf('Die Seriennummer %s ist bereits vergeben.', $serial); }
                    $serials[$serial] = true;
                }
                $draft['details'][$key] = [
                    'serial' => $serial,
                    'prefix' => $prefix,
                    'number' => $number,
                    'confirmed_serial' => $confirmed ? $serial : null,
                    'notes' => $notes,
                    'status' => $draft['details'][$key]['status'] ?? BuildStatus::InProgress->value,
                ];
            }
            $draft['site_id'] = $siteId;
            if ([] === $errors) {
                $this->saveDraft($request, $token, $draft);
                return $this->redirectToRoute('production_build_workflow_materials', ['token' => $token]);
            }
        }
        /** @var list<StorageLocation> $locations */
        $locations = [];
        foreach ($entityManager->getRepository(StorageLocation::class)->findAll() as $location) {
            if ($location instanceof StorageLocation && null === $location->getParent()) {
                $locations[] = $location;
            }
        }
        usort($locations, static fn(StorageLocation $a, StorageLocation $b): int => strcasecmp($a->getFullPath(), $b->getFullPath()));

        $offsets = [];
        foreach ($draft['nodes'] as $key => $node) {
            if (isset($draft['details'][$key]['number'])) { continue; }
            $content = $workflow->resolveNode($draft, (string) $key)['content'];
            $range = $numbers->rangeFor($content);
            $rangeId = $range?->getId() ?? 0;
            try { $suggestion = $numbers->suggest($content, $offsets[$rangeId] ?? 0); }
            catch (\RuntimeException $error) { $errors[] = $error->getMessage(); $suggestion = ['prefix' => '', 'number' => '']; }
            $offsets[$rangeId] = ($offsets[$rangeId] ?? 0) + 1;
            $draft['details'][$key] = [...($draft['details'][$key] ?? []), ...$suggestion];
        }
        return $this->render('production/build_workflow/details.html.twig', ['token' => $token, 'draft' => $draft, 'locations' => $locations, 'errors' => $errors]);
    }

    #[Route(path: '/materials', name: 'production_build_workflow_materials', methods: ['GET', 'POST'])]
    public function materials(string $token, Request $request, EntityManagerInterface $entityManager, ProductionBuildWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.build');
        $this->denyAccessUnlessGranted('@production_material.withdraw');
        $this->denyAccessUnlessGranted('@parts_stock.withdraw');
        $draft = $this->draft($request, $token);
        if (null !== $changed = $this->changedDraftResponse($draft, $workflow)) {
            return $changed;
        }
        $site = $entityManager->find(StorageLocation::class, $draft['site_id']);
        if (!$site instanceof StorageLocation) { return $this->redirectToRoute('production_build_workflow_details', ['token' => $token]); }
        $plan = $workflow->createMaterialPlan($draft, $site);
        $draft['materials_taken'] ??= [];
        $draft['lots'] = $workflow->allocateAvailableLots($plan);
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('build_materials_'.$token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid CSRF token.'); }
            $submittedTaken = $request->request->all('taken');
            $markAllTaken = $request->request->getBoolean('mark_all');
            $draft['materials_taken'] = [];
            foreach ($plan['items'] as $item) {
                $partId = (string) $item['part']->getId();
                $isTaken = $markAllTaken
                    ? 0 === $item['missing']
                    : '1' === (string) ($submittedTaken[$partId] ?? '');
                $draft['materials_taken'][$partId] = $isTaken;
            }
            $errors = $workflow->validateMaterialSelection($draft, $plan);
            if ($markAllTaken) {
                $this->saveDraft($request, $token, $draft);

                return $this->render('production/build_workflow/materials.html.twig', ['token' => $token, 'draft' => $draft, 'site' => $site, 'plan' => $plan, 'errors' => $errors]);
            }
            if ([] === $errors) {
                $this->saveDraft($request, $token, $draft);
                return $this->redirectToRoute('production_build_workflow_review', ['token' => $token]);
            }
        }
        $this->saveDraft($request, $token, $draft);

        return $this->render('production/build_workflow/materials.html.twig', ['token' => $token, 'draft' => $draft, 'site' => $site, 'plan' => $plan, 'errors' => $errors]);
    }

    #[Route(path: '/review', name: 'production_build_workflow_review', methods: ['GET', 'POST'])]
    public function review(string $token, Request $request, EntityManagerInterface $entityManager, ProductionBuildWorkflow $workflow, LoggerInterface $logger): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.build');
        $this->denyAccessUnlessGranted('@production_material.withdraw');
        $this->denyAccessUnlessGranted('@parts_stock.withdraw');
        $draft = $this->draft($request, $token);
        if (null !== $changed = $this->changedDraftResponse($draft, $workflow)) {
            return $changed;
        }
        $site = $entityManager->find(StorageLocation::class, $draft['site_id']);
        if (!$site instanceof StorageLocation) { return $this->redirectToRoute('production_build_workflow_details', ['token' => $token]); }
        $plan = $workflow->createMaterialPlan($draft, $site);
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('build_finish_'.$token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid CSRF token.'); }
            $finalDetails = $request->request->all('final');
            foreach ($draft['nodes'] as $key => $node) {
                $submitted = $finalDetails[$key] ?? [];
                $status = BuildStatus::tryFrom((string) ($submitted['status'] ?? BuildStatus::InProgress->value));
                $notes = trim((string) ($submitted['notes'] ?? $draft['details'][$key]['notes'] ?? ''));
                if (!$status instanceof BuildStatus) {
                    $errors[] = sprintf('%s: Ungültiger Status.', $node['name']);
                    continue;
                }
                if ('' === (string) ($draft['details'][$key]['serial'] ?? '') && '' === $notes) {
                    $errors[] = sprintf('%s: Ohne Seriennummer muss in den Notizen ein Grund stehen.', $node['name']);
                }
                $draft['details'][$key]['status'] = $status->value;
                $draft['details'][$key]['notes'] = $notes;
            }
            if ([] === $errors) {
                $user = $this->getUser();
                if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
                try {
                    $instance = $workflow->finalize($draft, $user);
                    $request->getSession()->remove('production_build_'.$token);
                    $this->addFlash('success', 'Der Bau wurde gestartet und das gewählte Material verbindlich ausgebucht.');
                    return $this->redirectToRoute('production_build_instance_show', ['id' => $instance->getId()]);
                } catch (StaleBuildDraftException $exception) {
                    return $this->renderChangedDraft($draft, $exception);
                } catch (\RuntimeException $exception) {
                    $errors[] = $exception->getMessage();
                } catch (\Throwable $exception) {
                    $logger->error('A production build could not be committed.', ['exception' => $exception]);
                    $errors[] = 'Der Bau konnte nicht gespeichert werden. Es wurden keine Änderungen übernommen; Details stehen ausschließlich im Serverprotokoll.';
                }
            }
        }

        return $this->render('production/build_workflow/review.html.twig', ['token' => $token, 'draft' => $draft, 'site' => $site, 'plan' => $plan, 'errors' => $errors, 'statuses' => BuildStatus::cases()]);
    }

    /** @param array<string, mixed> $draft */
    private function changedDraftResponse(array $draft, ProductionBuildWorkflow $workflow): ?Response
    {
        try {
            $workflow->assertCurrentDraft($draft);
        } catch (StaleBuildDraftException $exception) {
            return $this->renderChangedDraft($draft, $exception);
        }

        return null;
    }

    /** @param array<string, mixed> $draft */
    private function renderChangedDraft(array $draft, StaleBuildDraftException $exception): Response
    {
        return $this->render('production/build_workflow/changed.html.twig', [
            'draft' => $draft,
            'reason' => $exception->getMessage(),
        ], new Response(status: Response::HTTP_CONFLICT));
    }

    /** @return array<string, mixed> */
    private function draft(Request $request, string $token): array
    {
        $draft = $request->getSession()->get('production_build_'.$token);
        if (!is_array($draft)) { throw $this->createNotFoundException('Dieser Bauvorgang ist abgelaufen.'); }
        return $draft;
    }

    /** @param array<string, mixed> $draft */
    private function saveDraft(Request $request, string $token, array $draft): void
    {
        $request->getSession()->set('production_build_'.$token, $draft);
    }
}
