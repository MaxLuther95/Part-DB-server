<?php

declare(strict_types=1);

namespace App\Controller\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\Customer;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\Production\OrderAttachment;
use App\Entity\Production\OrderImportLine;
use App\Entity\Production\OrderImportMapping;
use App\Entity\Production\OrderPositionUnit;
use App\Entity\Production\ProductionProject;
use App\Entity\Production\ProductionProjectStatus;
use App\Entity\Production\ProjectAccessory;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use App\Form\Production\OrderImportMappingType;
use App\Repository\Production\CustomerProjectRepository;
use App\Repository\Production\CustomerRepository;
use App\Repository\Production\OrderImportMappingRepository;
use App\Repository\Production\ProductionProjectRepository;
use App\Services\Production\OrderAttachmentStorage;
use App\Services\Production\PdfOrderConfirmationParser;
use App\Services\Production\ProductionHistoryRecorder;
use App\Services\Production\ProjectPositionInitializer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/production')]
final class OrderImportController extends AbstractController
{
    private const MAX_IMPORT_LINES = 500;
    private const MAX_GENERATED_POSITIONS = 2000;

    #[Route(path: '/customer-projects/import', name: 'production_order_import', methods: ['GET', 'POST'])]
    public function upload(Request $request, PdfOrderConfirmationParser $parser, OrderImportMappingRepository $mappings, OrderAttachmentStorage $storage, LoggerInterface $logger): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.import');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('production_order_import', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $file = $request->files->get('pdf');
            if (!$file instanceof UploadedFile) {
                $this->addFlash('error', 'Bitte eine gültige PDF-Datei auswählen.');
            } else {
                try {
                    $storage->validateUpload($file, true);
                    $directory = $this->getParameter('kernel.project_dir').'/var/production-order-imports';
                    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                        throw new \RuntimeException('The temporary import directory could not be created.');
                    }
                    @chmod($directory, 0700);
                    $this->purgeOldImports($directory);
                    $token = bin2hex(random_bytes(24));
                    $temporaryPath = $directory.'/'.$token.'.pdf';
                    $originalFilename = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?? 'auftrag.pdf';
                    $originalFilename = mb_substr($originalFilename, 0, 180);
                    $file->move($directory, $token.'.pdf');
                    @chmod($temporaryPath, 0600);
                    $data = $parser->parseFile($temporaryPath);
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('error', $exception->getMessage());

                    return $this->redirectToRoute('production_order_import');
                } catch (\Throwable $exception) {
                    if (isset($temporaryPath) && is_file($temporaryPath)) {
                        @unlink($temporaryPath);
                    }
                    $logger->warning('Secure PDF order import rejected a document.', ['exception' => $exception]);
                    $this->addFlash('error', 'Die PDF konnte nicht sicher ausgelesen werden. Bitte prüfen Sie, ob es sich um eine digital erzeugte, unveränderte PDF handelt.');

                    return $this->redirectToRoute('production_order_import');
                }

                unset($data['raw_text']);
                foreach ($data['lines'] as &$line) {
                    $line['mapping_id'] = $mappings->findActiveForDescription($line['description'])?->getId();
                }
                unset($line);
                $request->getSession()->set('production_order_import_'.$token, [
                    'path' => $temporaryPath,
                    'original_filename' => $originalFilename,
                    'data' => $data,
                ]);

                return $this->redirectToRoute('production_order_import_review', ['token' => $token]);
            }
        }

        return $this->render('production/order_import/upload.html.twig', [
            'max_upload_size' => $storage->getMaximumFileSize(),
        ]);
    }

    #[Route(path: '/customer-projects/import/{token}', name: 'production_order_import_review', requirements: ['token' => '[a-f0-9]{48}'], methods: ['GET', 'POST'])]
    public function review(
        string $token,
        Request $request,
        EntityManagerInterface $entityManager,
        CustomerRepository $customers,
        ProductionProjectRepository $projects,
        CustomerProjectRepository $orders,
        OrderImportMappingRepository $mappings,
        ProjectPositionInitializer $positionInitializer,
        ProductionHistoryRecorder $historyRecorder,
        OrderAttachmentStorage $attachmentStorage,
        LoggerInterface $logger,
    ): Response {
        $this->denyAccessUnlessGranted('@production_orders.import');
        $sessionKey = 'production_order_import_'.$token;
        $state = $request->getSession()->get($sessionKey);
        if (!is_array($state) || !isset($state['path'], $state['original_filename'], $state['data']) || !is_file($state['path'])) {
            $this->addFlash('error', 'Dieser Import ist abgelaufen oder wurde bereits abgeschlossen.');

            return $this->redirectToRoute('production_order_import');
        }

        $data = $state['data'];
        $suggestedCustomer = '' !== $data['customer_number'] ? $customers->findOneBy(['customerNumber' => $data['customer_number']]) : null;
        $suggestedProject = '' !== $data['project_number'] ? $projects->findOneBy(['projectNumber' => $data['project_number']]) : null;
        $values = [
            'order_number' => $data['order_number'],
            'order_name' => $data['lines'][0]['description'] ?? '',
            'order_date' => $data['order_date'],
            'customer_id' => $suggestedCustomer?->getId() ?? 0,
            'customer_number' => $suggestedCustomer?->getCustomerNumber() ?? $data['customer_number'],
            'customer_name' => $suggestedCustomer?->getName() ?? $data['customer_name'],
            'project_id' => $suggestedProject?->getId() ?? 0,
            'project_number' => $suggestedProject?->getProjectNumber() ?? $data['project_number'],
            'project_name' => $suggestedProject?->getName() ?? '',
            'notes' => '' !== $data['reference'] ? 'Kundenreferenz: '.$data['reference'] : '',
            'lines' => $data['lines'],
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('production_order_import_review_'.$token, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $submittedLines = $request->request->all('lines');
            $values = [
                'order_number' => trim($request->request->getString('order_number')),
                'order_name' => trim($request->request->getString('order_name')),
                'order_date' => trim($request->request->getString('order_date')),
                'customer_id' => max(0, $request->request->getInt('customer_id')),
                'customer_number' => trim($request->request->getString('customer_number')),
                'customer_name' => trim($request->request->getString('customer_name')),
                'project_id' => max(0, $request->request->getInt('project_id')),
                'project_number' => trim($request->request->getString('project_number')),
                'project_name' => trim($request->request->getString('project_name')),
                'notes' => trim($request->request->getString('notes')),
                'lines' => $this->sanitizeLines($submittedLines),
            ];
            $errors = $this->validateReview($values, $customers, $projects, $orders, $mappings);
            if (count($submittedLines) > self::MAX_IMPORT_LINES) {
                $errors[] = sprintf('Ein PDF-Import darf höchstens %d Auftragspositionen enthalten.', self::MAX_IMPORT_LINES);
            }

            if ([] === $errors) {
                $connection = $entityManager->getConnection();
                $attachment = null;
                $connection->beginTransaction();
                try {
                    $customer = $values['customer_id'] > 0 ? $customers->find($values['customer_id']) : null;
                    if (!$customer instanceof Customer) {
                        $customer = (new Customer())->setCustomerNumber($values['customer_number']);
                        $entityManager->persist($customer);
                    }
                    $customer
                        ->setCustomerNumber($values['customer_number'])
                        ->setName($values['customer_name']);

                    $productionProject = $values['project_id'] > 0 ? $projects->find($values['project_id']) : null;
                    if (!$productionProject instanceof ProductionProject) {
                        $productionProject = (new ProductionProject())
                            ->setProjectNumber($values['project_number'])
                            ->setStatus(ProductionProjectStatus::Active);
                        $entityManager->persist($productionProject);
                    }
                    $productionProject
                        ->setProjectNumber($values['project_number'])
                        ->setName($values['project_name']);

                    $order = (new CustomerProject())
                        ->setOrderNumber($values['order_number'])
                        ->setName($values['order_name'])
                        ->setOrderDate(new \DateTimeImmutable($values['order_date']))
                        ->setCustomer($customer)
                        ->setProductionProject($productionProject)
                        ->setStatus(CustomerProjectStatus::Commissioned)
                        ->setDescription('Aus einer PDF-Auftragsbestätigung importiert.')
                        ->setNotes($values['notes']);
                    $entityManager->persist($order);
                    $entityManager->flush();

                    $positionNumber = 0;
                    foreach ($values['lines'] as $line) {
                        $mapping = $line['mapping_id'] > 0 ? $mappings->find($line['mapping_id']) : null;
                        $importLine = (new OrderImportLine())
                            ->setOrder($order)
                            ->setMapping($mapping)
                            ->setLineNumber($line['number'])
                            ->setDescription($line['description'])
                            ->setQuantity($line['quantity'])
                            ->setUnit($line['unit']);
                        $entityManager->persist($importLine);

                        if (!$mapping instanceof OrderImportMapping || !$mapping->isActive()) {
                            continue;
                        }
                        if ($mapping->getPart() instanceof Part) {
                            $entityManager->persist((new ProjectAccessory())
                                ->setCustomerProject($order)
                                ->setPart($mapping->getPart())
                                ->setQuantity($line['quantity'])
                                ->setNote(sprintf('PDF-Position %d: %s', $line['number'], $line['description'])));
                            continue;
                        }
                        for ($index = 1; $index <= $line['quantity']; ++$index) {
                            $position = (new ProjectPosition())
                                ->setCustomerProject($order)
                                ->setPosition($positionNumber++)
                                ->setQuantity(1)
                                ->setName($line['quantity'] > 1 ? sprintf('%s %d', $line['description'], $index) : $line['description']);
                            if ($mapping->getSystemTemplate() instanceof SystemTemplate) {
                                $position->setSystemTemplate($mapping->getSystemTemplate());
                            } elseif ($mapping->getTemplateProject() instanceof Project) {
                                $position->setTemplateProject($mapping->getTemplateProject());
                            }
                            foreach ($position->getBuildProjects() as $buildProject) {
                                $this->denyAccessUnlessGranted('read', $buildProject);
                            }
                            $entityManager->persist($position);
                            $positionInitializer->initializeRequiredDefaults($position);
                        }
                    }

                    $attachment = $attachmentStorage->adoptPdf($order, $state['path'], $state['original_filename']);
                    $entityManager->persist($attachment);
                    $historyRecorder->record($order, 'pdf_imported', $attachment->getOriginalFilename());
                    $entityManager->flush();
                    $connection->commit();
                    $request->getSession()->remove($sessionKey);
                    $this->addFlash('success', sprintf('Auftrag %s wurde aus der PDF angelegt.', $order->getOrderNumber()));

                    return $this->redirectToRoute('production_customer_project_show', ['id' => $order->getId()]);
                } catch (UniqueConstraintViolationException $exception) {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                    if ($attachment instanceof OrderAttachment) {
                        $attachmentStorage->remove($attachment);
                    }
                    $logger->notice('PDF order import rejected a duplicate number.', ['exception' => $exception]);
                    $entityManager->clear();
                    $errors[] = 'Die Auftrags-, Kunden- oder Projektnummer ist inzwischen bereits vorhanden. Bitte die erkannten Stammdaten erneut prüfen.';
                } catch (\Throwable $exception) {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                    if ($attachment instanceof OrderAttachment) {
                        $attachmentStorage->remove($attachment);
                    }
                    $logger->error('PDF order import could not be committed.', ['exception' => $exception]);
                    $entityManager->clear();
                    $errors[] = 'Der Auftrag konnte nicht gespeichert werden. Es wurden keine Änderungen übernommen; Details stehen ausschließlich im Serverprotokoll.';
                }
            }
        }

        $availableMappings = $mappings->findBy([], ['sourceDescription' => 'ASC']);
        $mappingUnits = [];
        foreach ($availableMappings as $mapping) {
            $mappingUnits[(string) $mapping->getId()] = $mapping->getOrderUnit()->value;
        }

        return $this->render('production/order_import/review.html.twig', [
            'token' => $token,
            'values' => $values,
            'errors' => $errors,
            'customers' => $customers->findBy([], ['customerNumber' => 'ASC']),
            'projects' => $projects->findBy([], ['projectNumber' => 'ASC']),
            'mappings' => $availableMappings,
            'order_units' => OrderPositionUnit::cases(),
            'order_unit_options' => array_map(
                static fn(OrderPositionUnit $unit): array => ['value' => $unit->value, 'label' => $unit->getLabel()],
                OrderPositionUnit::cases(),
            ),
            'mapping_units' => $mappingUnits,
            'original_filename' => $state['original_filename'],
        ]);
    }

    #[Route(path: '/customer-projects/{id}/attachments', name: 'production_order_attachment_upload', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function uploadAttachment(CustomerProject $order, Request $request, EntityManagerInterface $entityManager, OrderAttachmentStorage $storage, LoggerInterface $logger): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');
        if (!$this->isCsrfTokenValid('production_order_attachment_'.$order->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $file = $request->files->get('attachment');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Bitte eine gültige Datei auswählen.');
        } else {
            $attachment = null;
            try {
                $storage->validateUpload($file);
                $currentSize = 0;
                foreach ($order->getAttachments() as $existingAttachment) {
                    $currentSize += $existingAttachment->getFileSize();
                }
                if ($order->getAttachments()->count() >= OrderAttachmentStorage::MAX_ATTACHMENTS_PER_ORDER) {
                    throw new \InvalidArgumentException('Für diesen Auftrag ist die maximale Anzahl von 100 Dateianhängen erreicht.');
                }
                if ($currentSize + (int) $file->getSize() > OrderAttachmentStorage::MAX_TOTAL_SIZE_PER_ORDER) {
                    throw new \InvalidArgumentException('Die Dateianhänge dieses Auftrags dürfen zusammen höchstens 250 MB belegen.');
                }
                $attachment = $storage->storeUpload($order, $file);
                $entityManager->persist($attachment);
                $entityManager->flush();
                $this->addFlash('success', 'Der Dateianhang wurde gespeichert.');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable $exception) {
                if ($attachment instanceof OrderAttachment) {
                    $storage->remove($attachment);
                }
                $logger->error('An order attachment could not be stored.', ['order_id' => $order->getId(), 'exception' => $exception]);
                $this->addFlash('error', 'Der Dateianhang konnte nicht gespeichert werden. Details stehen ausschließlich im Serverprotokoll.');
            }
        }

        return $this->redirectToRoute('production_customer_project_show', ['id' => $order->getId()]);
    }

    #[Route(path: '/order-attachments/{id}/download', name: 'production_order_attachment_download', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function downloadAttachment(OrderAttachment $attachment, OrderAttachmentStorage $storage): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.read');
        try {
            $path = $storage->getAbsolutePath($attachment);
        } catch (\RuntimeException) {
            throw $this->createNotFoundException('Die Datei ist nicht mehr vorhanden.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Download-Options', 'noopen');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getOriginalFilename());

        return $response;
    }

    #[Route(path: '/order-attachments/{id}/delete', name: 'production_order_attachment_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deleteAttachment(OrderAttachment $attachment, Request $request, EntityManagerInterface $entityManager, OrderAttachmentStorage $storage): Response
    {
        $this->denyAccessUnlessGranted('@production_orders.edit');
        $order = $attachment->getOrder();
        if (!$this->isCsrfTokenValid('delete_order_attachment_'.$attachment->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $entityManager->remove($attachment);
        $entityManager->flush();
        $storage->remove($attachment);
        $this->addFlash('success', 'Der Dateianhang wurde gelöscht.');

        return $this->redirectToRoute('production_customer_project_show', ['id' => $order?->getId()]);
    }

    #[Route(path: '/order-import-mappings', name: 'production_order_import_mapping_index', methods: ['GET'])]
    public function mappingIndex(OrderImportMappingRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('@production_import_mappings.read');

        return $this->render('production/order_import_mapping/index.html.twig', [
            'mappings' => $repository->findBy([], ['sourceDescription' => 'ASC']),
        ]);
    }

    #[Route(path: '/order-import-mappings/new', name: 'production_order_import_mapping_new', methods: ['GET', 'POST'])]
    public function mappingNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_import_mappings.create');

        return $this->handleMappingForm(new OrderImportMapping(), $request, $entityManager);
    }

    #[Route(path: '/order-import-mappings/{id}/edit', name: 'production_order_import_mapping_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function mappingEdit(OrderImportMapping $mapping, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_import_mappings.edit');

        return $this->handleMappingForm($mapping, $request, $entityManager);
    }

    #[Route(path: '/order-import-mappings/{id}/delete', name: 'production_order_import_mapping_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function mappingDelete(OrderImportMapping $mapping, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('@production_import_mappings.delete');
        if (!$this->isCsrfTokenValid('delete_order_import_mapping_'.$mapping->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $entityManager->remove($mapping);
        $entityManager->flush();
        $this->addFlash('success', 'Die Importzuordnung wurde gelöscht.');

        return $this->redirectToRoute('production_order_import_mapping_index');
    }

    private function handleMappingForm(OrderImportMapping $mapping, Request $request, EntityManagerInterface $entityManager): Response
    {
        $isNew = null === $mapping->getId();
        $form = $this->createForm(OrderImportMappingType::class, $mapping);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($mapping);
            $entityManager->flush();
            $this->addFlash('success', 'Die Importzuordnung wurde gespeichert.');

            return $this->redirectToRoute('production_order_import_mapping_index');
        }

        return $this->render('production/form.html.twig', [
            'form' => $form,
            'title' => $isNew ? 'Importzuordnung anlegen' : 'Importzuordnung bearbeiten',
            'cancel_route' => 'production_order_import_mapping_index',
            'delete_route' => $isNew ? null : 'production_order_import_mapping_delete',
            'delete_permission' => '@production_import_mappings.delete',
            'delete_route_params' => ['id' => $mapping->getId()],
            'delete_token_id' => 'delete_order_import_mapping_'.$mapping->getId(),
            'delete_confirm' => 'Diese Importzuordnung wirklich löschen?',
        ]);
    }

    /** @param array<string, mixed> $lines @return list<array{number:int,description:string,quantity:int,unit:string,mapping_id:int}> */
    private function sanitizeLines(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            if (count($result) >= self::MAX_IMPORT_LINES) {
                break;
            }
            if (!is_array($line)) {
                continue;
            }
            $result[] = [
                'number' => (int) ($line['number'] ?? 0),
                'description' => trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($line['description'] ?? '')) ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit' => trim((string) ($line['unit'] ?? '')),
                'mapping_id' => max(0, (int) ($line['mapping_id'] ?? 0)),
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $values @return list<string> */
    private function validateReview(array $values, CustomerRepository $customers, ProductionProjectRepository $projects, CustomerProjectRepository $orders, OrderImportMappingRepository $mappings): array
    {
        $errors = [];
        foreach (['order_number' => 'Auftragsnummer', 'order_name' => 'Auftragsbezeichnung', 'order_date' => 'Datum', 'customer_number' => 'Kundennummer', 'customer_name' => 'Kundenname', 'project_number' => 'Projektnummer', 'project_name' => 'Projektbezeichnung'] as $field => $label) {
            if ('' === $values[$field]) {
                $errors[] = $label.' muss geprüft und ausgefüllt werden.';
            }
        }
        foreach (['order_number' => 64, 'customer_number' => 64, 'project_number' => 64, 'order_name' => 255, 'customer_name' => 255, 'project_name' => 255] as $field => $maximumLength) {
            if (mb_strlen($values[$field]) > $maximumLength) {
                $errors[] = sprintf('%s darf höchstens %d Zeichen enthalten.', match ($field) {
                    'order_number' => 'Die Auftragsnummer',
                    'customer_number' => 'Die Kundennummer',
                    'project_number' => 'Die Projektnummer',
                    'order_name' => 'Die Auftragsbezeichnung',
                    'customer_name' => 'Der Kundenname',
                    default => 'Die Projektbezeichnung',
                }, $maximumLength);
            }
        }
        if (mb_strlen($values['notes']) > 50000) {
            $errors[] = 'Die Notizen dürfen höchstens 50.000 Zeichen enthalten.';
        }
        if (null !== $orders->findOneBy(['projectNumber' => $values['order_number']])) {
            $errors[] = 'Diese Auftragsnummer ist bereits vorhanden.';
        }
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['order_date']);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (false === $parsedDate || $parsedDate->format('Y-m-d') !== $values['order_date'] || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            $errors[] = 'Das Datum ist ungültig.';
        }
        $selectedCustomer = $values['customer_id'] > 0 ? $customers->find($values['customer_id']) : null;
        if ($values['customer_id'] > 0 && !$selectedCustomer instanceof Customer) {
            $errors[] = 'Der ausgewählte Kunde ist nicht mehr vorhanden.';
        }
        if ($selectedCustomer instanceof Customer
            && ($selectedCustomer->getCustomerNumber() !== $values['customer_number'] || $selectedCustomer->getName() !== $values['customer_name'])
            && !$this->isGranted('@production_customers.edit')) {
            $errors[] = 'Bestehende Kundendaten dürfen mit Ihrer Berechtigung nicht geändert werden.';
        }
        $numberCustomer = '' !== $values['customer_number'] ? $customers->findOneBy(['customerNumber' => $values['customer_number']]) : null;
        if ($numberCustomer instanceof Customer && $numberCustomer !== $selectedCustomer) {
            $errors[] = 'Die Kundennummer gehört bereits zu einem anderen Kunden. Bitte diesen Kunden auswählen.';
        }
        $selectedProject = $values['project_id'] > 0 ? $projects->find($values['project_id']) : null;
        if ($values['project_id'] > 0 && !$selectedProject instanceof ProductionProject) {
            $errors[] = 'Das ausgewählte Projekt ist nicht mehr vorhanden.';
        }
        if ($selectedProject instanceof ProductionProject
            && ($selectedProject->getProjectNumber() !== $values['project_number'] || $selectedProject->getName() !== $values['project_name'])
            && !$this->isGranted('@production_projects.edit')) {
            $errors[] = 'Bestehende Projektdaten dürfen mit Ihrer Berechtigung nicht geändert werden.';
        }
        $numberProject = '' !== $values['project_number'] ? $projects->findOneBy(['projectNumber' => $values['project_number']]) : null;
        if ($numberProject instanceof ProductionProject && $numberProject !== $selectedProject) {
            $errors[] = 'Die Projektnummer gehört bereits zu einem bestehenden Projekt. Bitte dieses Projekt auswählen.';
        }
        if ([] === $values['lines']) {
            $errors[] = 'Es wurde keine Auftragsposition erkannt. Bitte die PDF prüfen.';
        }
        $generatedPositions = 0;
        foreach ($values['lines'] as $line) {
            if ('' === $line['description']) {
                $errors[] = sprintf('Die Beschreibung der PDF-Position %d darf nicht leer sein.', $line['number']);
            }
            if (mb_strlen($line['description']) > 255) {
                $errors[] = sprintf('Die Beschreibung der PDF-Position %d darf höchstens 255 Zeichen enthalten.', $line['number']);
            }
            $unit = OrderPositionUnit::tryFrom($line['unit']);
            if (!$unit instanceof OrderPositionUnit) {
                $errors[] = sprintf('Die Einheit der PDF-Position %d ist nicht zulässig.', $line['number']);
            }
            if ($line['quantity'] < 1 || $line['quantity'] > 10000) {
                $errors[] = sprintf('Die Anzahl der PDF-Position %d muss zwischen 1 und 10.000 liegen.', $line['number']);
            }
            if ($line['number'] < 1 || $line['number'] > 1000000) {
                $errors[] = 'Eine PDF-Positionsnummer liegt außerhalb des zulässigen Bereichs.';
            }
            if ($line['mapping_id'] <= 0) {
                continue;
            }
            $mapping = $mappings->find($line['mapping_id']);
            if (!$mapping instanceof OrderImportMapping || !$mapping->isActive()) {
                $errors[] = sprintf('Die Zuordnung der PDF-Position %d ist nicht mehr gültig.', $line['number']);
                continue;
            }
            if ($unit instanceof OrderPositionUnit && $mapping->getOrderUnit() !== $unit) {
                $errors[] = sprintf(
                    'Die Einheit der PDF-Position %d passt nicht zur Zuordnung. Für %s ist %s festgelegt.',
                    $line['number'],
                    $mapping->getTargetLabel(),
                    $mapping->getOrderUnit()->getLabel(),
                );
            }
            if ($mapping->getSystemTemplate() instanceof SystemTemplate || $mapping->getTemplateProject() instanceof Project) {
                $generatedPositions += $line['quantity'];
            }
        }
        if ($generatedPositions > self::MAX_GENERATED_POSITIONS) {
            $errors[] = sprintf('Ein einzelner Import darf höchstens %d Fertigungspositionen erzeugen.', self::MAX_GENERATED_POSITIONS);
        }

        return $errors;
    }

    private function purgeOldImports(string $directory): void
    {
        foreach (glob($directory.'/*.pdf') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < time() - 86400) {
                @unlink($path);
            }
        }
    }
}
