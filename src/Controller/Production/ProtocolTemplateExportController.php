<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Production\ProtocolTemplateRevision;
use App\Form\Production\ProtocolTemplateExportType;
use App\Services\Production\ProtocolTemplateExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/production')]
final class ProtocolTemplateExportController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route(path: '/protocol-templates/export', name: 'production_protocol_template_export', methods: ['GET', 'POST'])]
    public function select(Request $request, ProtocolTemplateExporter $exporter): Response
    {
        $this->denyExportUnlessGranted();
        $form = $this->createForm(ProtocolTemplateExportType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $revisions = array_values($form->get('revisions')->getData()->toArray());

            return $this->download($exporter->export($revisions), $this->filename($revisions));
        }

        return $this->render('production/protocol_template/export.html.twig', ['form' => $form]);
    }

    #[Route(path: '/protocol-revisions/{id}/export', name: 'production_protocol_revision_export', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function revision(ProtocolTemplateRevision $revision, ProtocolTemplateExporter $exporter): Response
    {
        $this->denyExportUnlessGranted();

        return $this->download(
            $exporter->export([$revision]),
            $this->filename([$revision])
        );
    }

    private function denyExportUnlessGranted(): void
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.read');
        if (! $this->isGranted('@users.edit_permissions') && ! $this->isGranted('@groups.edit_permissions')) {
            throw $this->createAccessDeniedException('Only administrators may export protocol templates.');
        }
    }

    private function download(string $json, string $filename): Response
    {
        // Preserve the actual Unicode name while supporting older download clients.
        $fallback = preg_replace('/[^\x20-\x7E]/u', '_', $filename) ?? 'Laufzettel.json';

        return new Response($json, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param list<ProtocolTemplateRevision> $revisions */
    private function filename(array $revisions): string
    {
        if (1 !== count($revisions)) {
            return 'Laufzettel_Sammelexport.json';
        }

        $revision = $revisions[0];
        $name = $revision->getTemplate()?->getName() ?? '';
        // Names remain recognizable; only unsafe filename characters are replaced.
        $name = preg_replace('~[<>:"/\\\\|?*%\p{C}]+~u', '_', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim(mb_strcut($name, 0, 180, 'UTF-8'), ' ._');
        $status = $this->translator->trans('production.protocol.revision_status.'.$revision->getStatus()->value, [], 'production');

        return sprintf('Laufzettel_%s_%s.json', '' !== $name ? $name : 'Vorlage', $status);
    }
}
