<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\OrderImportLine;
use App\Entity\Production\OrderImportLineDisposition;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use App\Form\Production\OrderImportLineAssignmentType;
use App\Services\Production\OrderImportLineResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/production/import-lines')]
final class OrderImportLineController extends AbstractController
{
    #[Route('/{id}/assign', name: 'production_import_line_assign', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function assign(OrderImportLine $line, Request $request, OrderImportLineResolver $resolver): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.read');
        $this->denyAccessUnlessGranted('@production_orders.edit');
        $orderId = $line->getOrder()?->getId();
        $form = $this->createForm(OrderImportLineAssignmentType::class, ['unit' => \App\Entity\Production\OrderPositionUnit::fromImportedValue($line->getUnit()), 'expected' => $line->getDisposition()->value]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $targets = array_values(array_filter([$data['part'], $data['systemTemplate'], $data['templateProject']], static fn(mixed $target): bool => null !== $target));
            if (1 !== count($targets)) {
                $form->addError(new FormError('Bitte genau eine Systemvorlage, ein Bauprojekt oder ein Lagerteil auswählen.'));
            } else {
                $target = $targets[0];
                if ($target instanceof Part || $target instanceof Project) {
                    $this->denyAccessUnlessGranted('read', $target);
                } elseif ($target instanceof SystemTemplate) {
                    foreach ($target->getBaseProjects() as $project) {
                        $this->denyAccessUnlessGranted('read', $project);
                    }
                }
                try {
                    $resolver->assign($line, $target, $data['unit'], $data['expected']);
                    $this->addFlash('success', 'Die Auftragsposition wurde zugeordnet.');
                } catch (\DomainException $exception) {
                    // The transaction was rolled back; redirect without reusing managed entities.
                    $this->addFlash('error', $exception->getMessage());
                }

                return $this->redirectToRoute('production_customer_project_show', ['id' => $orderId]);
            }
        }

        return $this->render('production/order_import/assign.html.twig', ['line' => $line, 'form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/{action}', name: 'production_import_line_classify', requirements: ['id' => '\d+', 'action' => 'note|pending'], methods: ['POST'])]
    public function classify(OrderImportLine $line, string $action, Request $request, OrderImportLineResolver $resolver): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.read');
        $this->denyAccessUnlessGranted('@production_orders.edit');
        if (!$this->isCsrfTokenValid('import_line_'.$line->getId().'_'.$action, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $orderId = $line->getOrder()?->getId();
        try {
            $resolver->classify($line, OrderImportLineDisposition::from($action), $request->request->getString('expected'));
            $this->addFlash('success', 'Die Auftragsposition wurde aktualisiert.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('production_customer_project_show', ['id' => $orderId]);
    }
}
