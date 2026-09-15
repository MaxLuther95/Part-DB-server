<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Services\Production\ProtocolTemplateImporter;
use App\Services\Production\ProtocolTemplateImportValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/production/protocol-templates/import')]
final class ProtocolTemplateImportController extends AbstractController
{
    private const SESSION_KEY = 'production.protocol_template_import';

    #[Route(path: '', name: 'production_protocol_template_import', methods: ['GET', 'POST'])]
    public function upload(Request $request, ProtocolTemplateImportValidator $validator, TranslatorInterface $translator): Response
    {
        $this->denyImportUnlessGranted();
        $form = $this->createFormBuilder(null, ['translation_domain' => 'production', 'csrf_token_id' => 'protocol_import_upload'])
            ->add('file', FileType::class, [
                'label' => 'production.protocol.import.file',
                'attr' => ['accept' => '.json,application/json'],
                'constraints' => [new NotNull(), new File(maxSize: ProtocolTemplateImportValidator::MAX_BYTES)],
            ])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();
            try {
                // Content validation is authoritative; MIME detection often labels JSON as text/plain.
                $json = file_get_contents($file->getPathname(), false, null, 0, ProtocolTemplateImportValidator::MAX_BYTES + 1);
                if (false === $json) {
                    throw new \DomainException('Unable to read the uploaded file.');
                }
                $validator->parse($json);
                $request->getSession()->set(self::SESSION_KEY, [
                    'id' => bin2hex(random_bytes(16)),
                    'owner' => $this->getUser()?->getUserIdentifier(),
                    'expires' => time() + 1800,
                    'json' => $json,
                ]);

                return $this->redirectToRoute('production_protocol_template_import_preview');
            } catch (\DomainException $exception) {
                $form->addError(new FormError($translator->trans('production.protocol.import.invalid_file', [], 'production').' '.$exception->getMessage()));
            }
        }

        return $this->render('production/protocol_template/import.html.twig', ['form' => $form, 'document' => null]);
    }

    #[Route(path: '/preview', name: 'production_protocol_template_import_preview', methods: ['GET', 'POST'])]
    public function preview(Request $request, ProtocolTemplateImportValidator $validator, ProtocolTemplateImporter $importer, TranslatorInterface $translator, LoggerInterface $logger): Response
    {
        $this->denyImportUnlessGranted();
        $session = $request->getSession();
        $pending = $session->get(self::SESSION_KEY);
        if (! is_array($pending) || $pending['expires'] < time() || $pending['owner'] !== $this->getUser()?->getUserIdentifier()) {
            $session->remove(self::SESSION_KEY);
            $this->addFlash('warning', $translator->trans('production.protocol.import.expired', [], 'production'));

            return $this->redirectToRoute('production_protocol_template_import');
        }
        $document = $validator->parse($pending['json']);
        $builder = $this->createFormBuilder(null, ['translation_domain' => 'production', 'csrf_token_id' => 'protocol_import_confirm'])
            ->add('stage_id', HiddenType::class, ['data' => $pending['id']]);
        foreach ($document['templates'] as $index => $template) {
            $choices = [];
            foreach ($template['revisions'] as $revision) {
                $fieldCount = array_sum(array_map(static fn (array $section): int => count($section['fields']), $revision['sections']));
                $label = sprintf('v%d · %s · %d %s', $revision['number'],
                    $translator->trans('production.protocol.revision_status.'.$revision['status'], [], 'production'),
                    $fieldCount, $translator->trans('production.protocol.field.plural', [], 'production'));
                $choices[$label] = $revision['number'];
            }
            $builder->add($builder->create('template_'.$index, FormType::class, ['label' => $template['name']])
                ->add('revision', ChoiceType::class, [
                    'label' => 'production.protocol.import.source_revision', 'choices' => $choices,
                    'choice_translation_domain' => false, 'required' => false,
                    'placeholder' => 'production.protocol.import.skip',
                    'data' => $pending['choices']['template_'.$index]['revision'] ?? null,
                ])
                ->add('name', TextType::class, [
                    'label' => 'production.protocol.import.target_name', 'data' => $pending['choices']['template_'.$index]['name'] ?? $template['name'],
                    'required' => false, 'constraints' => [new Length(max: 255)],
                ]));
        }
        $form = $builder->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $posted = $request->request->all('form');
            if (! is_string($posted['stage_id'] ?? null) || ! hash_equals($pending['id'], $posted['stage_id'])) {
                throw $this->createAccessDeniedException('The import preview has been replaced.');
            }
            $selection = [];
            foreach ($document['templates'] as $index => $template) {
                $data = $form->get('template_'.$index)->getData();
                if (null !== $data['revision']) {
                    $selection[$index] = ['revision' => $data['revision'], 'name' => (string) $data['name']];
                }
            }
            try {
                $pending['choices'] = $form->getData();
                $session->set(self::SESSION_KEY, $pending);
                $created = $importer->import($pending['json'], $selection);
            } catch (\DomainException $exception) {
                // Redirect also gives a clean entity manager after any transaction rollback.
                $this->addFlash('error', $translator->trans($exception->getMessage(), [], 'production'));

                return $this->redirectToRoute('production_protocol_template_import_preview');
            } catch (\Doctrine\DBAL\Exception $exception) {
                $logger->error('Protocol template import database operation failed.', ['exception' => $exception]);
                $this->addFlash('error', $translator->trans('production.protocol.import.database_error', [], 'production'));

                return $this->redirectToRoute('production_protocol_template_import_preview');
            }
            $session->remove(self::SESSION_KEY);
            $this->addFlash('success', $translator->trans('production.protocol.import.success', ['%count%' => count($created)], 'production'));

            return 1 === count($created)
                ? $this->redirectToRoute('production_protocol_template_show', ['id' => $created[0]->getId()])
                : $this->redirectToRoute('production_protocol_template_index');
        }

        return $this->render('production/protocol_template/import.html.twig', ['form' => $form, 'document' => $document]);
    }

    #[Route(path: '/cancel', name: 'production_protocol_template_import_cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        $this->denyImportUnlessGranted();
        if (! $this->isCsrfTokenValid('protocol_import_cancel', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $pending = $request->getSession()->get(self::SESSION_KEY);
        if (is_array($pending) && ! hash_equals($pending['id'], $request->request->getString('stage_id'))) {
            throw $this->createAccessDeniedException('The import preview has been replaced.');
        }
        $request->getSession()->remove(self::SESSION_KEY);

        return $this->redirectToRoute('production_protocol_template_index');
    }

    private function denyImportUnlessGranted(): void
    {
        $this->denyAccessUnlessGranted('@production_protocol_templates.read');
        $this->denyAccessUnlessGranted('@production_protocol_templates.create');
        if (! $this->isGranted('@users.edit_permissions') && ! $this->isGranted('@groups.edit_permissions')) {
            throw $this->createAccessDeniedException('Only administrators may import protocol templates.');
        }
    }
}
