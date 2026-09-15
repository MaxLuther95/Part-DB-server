<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Production\SerialNumberRange;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use App\Form\Production\SerialNumberRangeType;
use App\Services\Production\SerialNumberManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/production/serial-number-ranges')]
final class SerialNumberRangeController extends AbstractController
{
    private function denyUnlessAdmin(): void
    {
        if (! $this->isGranted('@users.edit_permissions') && ! $this->isGranted('@groups.edit_permissions')) {
            throw $this->createAccessDeniedException('Only administrators may configure serial-number ranges.');
        }
    }

    #[Route('', name: 'production_serial_range_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        $this->denyUnlessAdmin();

        return $this->render('production/serial_range/index.html.twig', [
            'ranges' => $em->getRepository(SerialNumberRange::class)->findBy([], [
                'prefix' => 'ASC',
            ]),
        ]);
    }

    #[Route('/new', name: 'production_serial_range_new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em, SerialNumberManager $numbers): Response
    {
        return $this->edit(new SerialNumberRange(), $request, $em, $numbers);
    }

    #[Route('/{id}/edit', name: 'production_serial_range_edit', requirements: [
        'id' => '\d+',
    ], methods: ['GET', 'POST'])]
    public function edit(SerialNumberRange $range, Request $request, EntityManagerInterface $em, SerialNumberManager $numbers): Response
    {
        $this->denyUnlessAdmin();
        $form = $this->createForm(SerialNumberRangeType::class, $range);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ([...$range->getSystems(), ...$range->getProjects()] as $content) {
                $assigned = $numbers->rangeFor($content);
                if (null !== $assigned && $assigned !== $range) {
                    $form->addError(new FormError(sprintf('%s ist bereits dem Nummernkreis %s zugeordnet. Bitte zuerst dort lösen.', $content->getName(), $assigned->getName())));
                }
            }
            if ($form->isValid()) {
                $db = $em->getConnection();
                $db->beginTransaction();
                try {
                    if (null !== $range->getId()) {
                        $lock = $db->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
                        $version = $db->fetchOne('SELECT version FROM production_serial_number_ranges WHERE id = ?'.$lock, [$range->getId()]);
                        if ((string) $version !== $form->get('editVersion')->getData()) {
                            throw new \RuntimeException('Der Nummernkreis wurde inzwischen geändert oder verwendet. Bitte die Seite neu laden.');
                        }
                    }
                    $em->persist($range);
                    $em->flush();
                    // Collection-only edits must invalidate other open editors too.
                    $db->executeStatement('UPDATE production_serial_number_ranges SET version = version + 1 WHERE id = ?', [$range->getId()]);
                    $db->commit();
                    $this->addFlash('success', 'Nummernkreis und Zuordnungen gespeichert.');

                    return $this->redirectToRoute('production_serial_range_index');
                } catch (UniqueConstraintViolationException) {
                    $db->rollBack();
                    $form->addError(new FormError('Präfix oder Bautyp-Zuordnung ist inzwischen vergeben. Bitte die Seite neu laden.'));
                } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                    $db->rollBack();
                    if (!in_array($error->getCode(), [1020, 1205, 1213], true)) {
                        throw $error;
                    }
                    $form->addError(new FormError('Der Nummernkreis wird gleichzeitig verwendet. Bitte die Seite neu laden; es wurde nichts gespeichert.'));
                } catch (\RuntimeException $error) {
                    $db->rollBack();
                    $form->addError(new FormError($error->getMessage()));
                } catch (\Throwable $error) {
                    $db->rollBack();
                    throw $error;
                }
            }
        }

        return $this->render('production/serial_range/edit.html.twig', [
            'form' => $form,
            'range' => $range,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/suggest/{type}/{id}', name: 'production_serial_range_suggest', requirements: [
        'type' => 'system|project',
        'id' => '\d+',
    ], methods: ['GET'])]
    public function suggest(string $type, int $id, EntityManagerInterface $em, SerialNumberManager $numbers): Response
    {
        $this->denyAccessUnlessGranted('@production_build_instances.read');
        if (! $this->isGranted('@production_build_instances.create') && ! $this->isGranted('@production_build_instances.edit') && ! $this->isGranted('@production_build_instances.build')) {
            throw $this->createAccessDeniedException();
        }
        $content = $em->find('system' === $type ? SystemTemplate::class : Project::class, $id);
        if (! $content instanceof SystemTemplate && ! $content instanceof Project) {
            throw $this->createNotFoundException();
        }
        if ($content instanceof Project) {
            $this->denyAccessUnlessGranted('read', $content);
        }
        try {
            $result = $numbers->suggest($content);
        } catch (\RuntimeException $error) {
            return $this->json([
                'error' => $error->getMessage(),
            ], 422, [
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return $this->json($result, headers: [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
