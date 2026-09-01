<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Parts\StorageLocation;
use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\SystemTemplate;
use App\Entity\UserSystem\User;
use App\Services\Production\ProductionBuildWorkflow;
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
    public function details(string $token, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.build');
        $draft = $this->draft($request, $token);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('build_details_'.$token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid CSRF token.'); }
            $siteId = $request->request->getInt('site_id');
            $details = $request->request->all('details');
            $errors = [];
            $serials = [];
            $selectedSite = $entityManager->find(StorageLocation::class, $siteId);
            if (!$selectedSite instanceof StorageLocation || null !== $selectedSite->getParent()) { $errors[] = 'Bitte einen gültigen Fertigungsstandort wählen.'; }
            foreach ($draft['nodes'] as $key => $node) {
                $serial = trim((string) ($details[$key]['serial'] ?? ''));
                $notes = trim((string) ($details[$key]['notes'] ?? ''));
                if ('' === $serial && '' === $notes) { $errors[] = sprintf('%s: Ohne Seriennummer muss in den Notizen ein Grund stehen.', $node['name']); }
                if ('' !== $serial) {
                    if (isset($serials[$serial]) || null !== $entityManager->getRepository(BuildInstance::class)->findOneBy(['serialNumber' => $serial])) { $errors[] = sprintf('Die Seriennummer %s ist bereits vergeben.', $serial); }
                    $serials[$serial] = true;
                }
                $draft['details'][$key] = [
                    'serial' => $serial,
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

        return $this->render('production/build_workflow/details.html.twig', ['token' => $token, 'draft' => $draft, 'locations' => $locations, 'errors' => $errors ?? []]);
    }

    #[Route(path: '/materials', name: 'production_build_workflow_materials', methods: ['GET', 'POST'])]
    public function materials(string $token, Request $request, EntityManagerInterface $entityManager, ProductionBuildWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.build');
        $this->denyAccessUnlessGranted('@production_material.withdraw');
        $this->denyAccessUnlessGranted('@parts_stock.withdraw');
        $draft = $this->draft($request, $token);
        $site = $entityManager->find(StorageLocation::class, $draft['site_id']);
        if (!$site instanceof StorageLocation) { return $this->redirectToRoute('production_build_workflow_details', ['token' => $token]); }
        $plan = $workflow->createMaterialPlan($draft, $site);
        $draft['materials_taken'] ??= [];
        $errors = [];
        if ([] === $draft['lots']) {
            foreach ($plan['items'] as $item) {
                $remaining = $item['remaining'];
                foreach ($item['lots'] as $row) {
                    $take = min($remaining, $row['available']);
                    $draft['lots'][(string) $item['part']->getId()][(string) $row['lot']->getId()] = $take;
                    $remaining -= $take;
                }
            }
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('build_materials_'.$token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid CSRF token.'); }
            $submitted = $request->request->all('lots');
            $submittedTaken = $request->request->all('taken');
            $markAllTaken = $request->request->getBoolean('mark_all');
            $draft['lots'] = [];
            $draft['materials_taken'] = [];
            foreach ($plan['items'] as $item) {
                $partId = (string) $item['part']->getId();
                $isTaken = $markAllTaken
                    ? 0 === $item['missing']
                    : '1' === (string) ($submittedTaken[$partId] ?? '');
                $draft['materials_taken'][$partId] = $isTaken;
                if (!$isTaken) {
                    $errors[] = sprintf('%s: Bitte die Materialentnahme bestätigen.', $item['part']->getName());
                }
                $sum = 0;
                foreach ($item['lots'] as $row) {
                    $raw = $submitted[$partId][(string) $row['lot']->getId()] ?? 0;
                    $amount = filter_var($raw, FILTER_VALIDATE_INT);
                    if (false === $amount || $amount < 0 || $amount > $row['available']) { $errors[] = sprintf('%s: Ungültige Entnahmemenge.', $item['part']->getName()); continue; }
                    $draft['lots'][$partId][(string) $row['lot']->getId()] = $amount;
                    $sum += $amount;
                }
                if ($sum !== $item['remaining']) { $errors[] = sprintf('%s: Es müssen genau %d Stück aus Lagerplätzen gewählt werden.', $item['part']->getName(), $item['remaining']); }
            }
            if (!$plan['complete']) {
                $errors[] = 'Am gewählten Standort ist nicht genügend Material verfügbar.';
            }
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
                } catch (\RuntimeException $exception) {
                    $errors[] = $exception->getMessage();
                } catch (\Throwable $exception) {
                    $logger->error('A production build could not be committed.', ['exception' => $exception]);
                    $errors[] = 'Der Bau konnte nicht gespeichert werden. Es wurden keine Änderungen übernommen; Details stehen ausschließlich im Serverprotokoll.';
                }
            }
        }

        return $this->render('production/build_workflow/review.html.twig', ['token' => $token, 'draft' => $draft, 'site' => $site, 'plan' => $plan, 'errors' => $errors]);
    }

    /** @return array<string, mixed> */
    private function draft(Request $request, string $token): array
    {
        $draft = $request->getSession()->get('production_build_'.$token);
        if (!is_array($draft)) { throw $this->createNotFoundException('Dieser Bauvorgang ist abgelaufen.'); }
        if (ProductionBuildWorkflow::DRAFT_VERSION !== ($draft['version'] ?? null)) {
            $request->getSession()->remove('production_build_'.$token);
            throw $this->createNotFoundException('Dieser Bauvorgang verwendet noch den alten Ablauf. Bitte starten Sie ihn erneut.');
        }
        return $draft;
    }

    /** @param array<string, mixed> $draft */
    private function saveDraft(Request $request, string $token, array $draft): void
    {
        $request->getSession()->set('production_build_'.$token, $draft);
    }
}
