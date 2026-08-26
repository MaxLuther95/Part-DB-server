<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Parts\StorageLocation;
use App\Entity\Production\BuildInstance;
use App\Entity\Production\SystemTemplate;
use App\Entity\UserSystem\User;
use App\Services\Production\ProductionBuildWorkflow;
use Doctrine\ORM\EntityManagerInterface;
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
        $this->denyAccessUnlessGranted('@projects.edit');
        $draft = $this->draft($request, $token);
        $next = $workflow->getNextUnconfiguredNode($draft);

        return null !== $next
            ? $this->redirectToRoute('production_build_workflow_configure', ['token' => $token, 'node' => $next])
            : $this->redirectToRoute('production_build_workflow_details', ['token' => $token]);
    }

    #[Route(path: '/configure/{node}', name: 'production_build_workflow_configure', requirements: ['node' => 'n\d+'], methods: ['GET', 'POST'])]
    public function configure(string $token, string $node, Request $request, ProductionBuildWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');
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
        $this->denyAccessUnlessGranted('@projects.edit');
        $draft = $this->draft($request, $token);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('build_details_'.$token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid CSRF token.'); }
            $siteId = $request->request->getInt('site_id');
            $details = $request->request->all('details');
            $errors = [];
            $serials = [];
            if (!$entityManager->find(StorageLocation::class, $siteId) instanceof StorageLocation) { $errors[] = 'Bitte einen gültigen Fertigungsstandort wählen.'; }
            foreach ($draft['nodes'] as $key => $node) {
                $serial = trim((string) ($details[$key]['serial'] ?? ''));
                $notes = trim((string) ($details[$key]['notes'] ?? ''));
                if ('' === $serial && '' === $notes) { $errors[] = sprintf('%s: Ohne Seriennummer muss in den Notizen ein Grund stehen.', $node['name']); }
                if ('' !== $serial) {
                    if (isset($serials[$serial]) || null !== $entityManager->getRepository(BuildInstance::class)->findOneBy(['serialNumber' => $serial])) { $errors[] = sprintf('Die Seriennummer %s ist bereits vergeben.', $serial); }
                    $serials[$serial] = true;
                }
                $draft['details'][$key] = ['serial' => $serial, 'notes' => $notes];
            }
            $draft['site_id'] = $siteId;
            if ([] === $errors) {
                $this->saveDraft($request, $token, $draft);
                return $this->redirectToRoute('production_build_workflow_materials', ['token' => $token]);
            }
        }
        $locations = $entityManager->getRepository(StorageLocation::class)->findAll();
        usort($locations, static fn(StorageLocation $a, StorageLocation $b): int => strcasecmp($a->getFullPath(), $b->getFullPath()));

        return $this->render('production/build_workflow/details.html.twig', ['token' => $token, 'draft' => $draft, 'locations' => $locations, 'errors' => $errors ?? []]);
    }

    #[Route(path: '/materials', name: 'production_build_workflow_materials', methods: ['GET', 'POST'])]
    public function materials(string $token, Request $request, EntityManagerInterface $entityManager, ProductionBuildWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');
        $this->denyAccessUnlessGranted('@parts_stock.withdraw');
        $draft = $this->draft($request, $token);
        $site = $entityManager->find(StorageLocation::class, $draft['site_id']);
        if (!$site instanceof StorageLocation) { return $this->redirectToRoute('production_build_workflow_details', ['token' => $token]); }
        $plan = $workflow->createMaterialPlan($draft, $site);
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
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('build_materials_'.$token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid CSRF token.'); }
            $submitted = $request->request->all('lots');
            $draft['lots'] = [];
            foreach ($plan['items'] as $item) {
                $sum = 0;
                foreach ($item['lots'] as $row) {
                    $raw = $submitted[(string) $item['part']->getId()][(string) $row['lot']->getId()] ?? 0;
                    $amount = filter_var($raw, FILTER_VALIDATE_INT);
                    if (false === $amount || $amount < 0 || $amount > $row['available']) { $errors[] = sprintf('%s: Ungültige Entnahmemenge.', $item['part']->getName()); continue; }
                    $draft['lots'][(string) $item['part']->getId()][(string) $row['lot']->getId()] = $amount;
                    $sum += $amount;
                }
                if ($sum !== $item['remaining']) { $errors[] = sprintf('%s: Es müssen genau %d Stück aus Lagerplätzen gewählt werden.', $item['part']->getName(), $item['remaining']); }
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
    public function review(string $token, Request $request, EntityManagerInterface $entityManager, ProductionBuildWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted('@projects.edit');
        $this->denyAccessUnlessGranted('@parts_stock.withdraw');
        $draft = $this->draft($request, $token);
        $site = $entityManager->find(StorageLocation::class, $draft['site_id']);
        if (!$site instanceof StorageLocation) { return $this->redirectToRoute('production_build_workflow_details', ['token' => $token]); }
        $plan = $workflow->createMaterialPlan($draft, $site);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('build_finish_'.$token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException('Invalid CSRF token.'); }
            $user = $this->getUser();
            if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
            try {
                $instance = $workflow->finalize($draft, $user);
                $request->getSession()->remove('production_build_'.$token);
                $this->addFlash('success', 'Der Bau wurde gestartet und das gewählte Material verbindlich ausgebucht.');
                return $this->redirectToRoute('production_build_instance_show', ['id' => $instance->getId()]);
            } catch (\Throwable $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('production/build_workflow/review.html.twig', ['token' => $token, 'draft' => $draft, 'site' => $site, 'plan' => $plan]);
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
