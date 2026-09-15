<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\{ManufacturingSnapshot, SystemTemplate};
use App\Entity\ProjectSystem\Project;
use Doctrine\Migrations\Query\Query;
use Doctrine\ORM\EntityManagerInterface;

/** Reads old columns only; returns SQL for Doctrine to execute or display as a dry run. */
final readonly class ManufacturingSnapshotMigrationPlan
{
    public function __construct(private EntityManagerInterface $entityManager, private ManufacturingSnapshotFactory $factory) {}

    /** @return list<Query> */
    public function statements(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, parent_id, system_template_id, template_project_id FROM production_project_positions ORDER BY id');
        $byId = array_column($rows, null, 'id');
        $positions = [];
        $roots = [];
        $snapshots = [];
        $visiting = [];
        $visit = function (int $id) use (&$visit, &$positions, &$roots, &$snapshots, &$visiting, $byId): void {
            if (array_key_exists($id, $positions)) { return; }
            if (isset($visiting[$id]) || count($visiting) >= 64) { throw new \DomainException('Die vorhandene Auftragshierarchie ist zyklisch oder zu tief.'); }
            $visiting[$id] = true;
            $row = $byId[$id];
            $contentId = $row['system_template_id'] ?? $row['template_project_id'];
            if (null === $contentId) {
                $positions[$id] = null; // Preserve historical deleted references, without inventing definitions.
                unset($visiting[$id]);
                return;
            }
            $type = null !== $row['system_template_id'] ? 'system' : 'project';
            $key = $type.'_'.$contentId;
            $parent = null;
            if (null !== $row['parent_id']) {
                $visit((int) $row['parent_id']);
                $parent = $positions[$row['parent_id']];
            }
            if (null !== $parent) {
                $snapshot = $parent['snapshot'];
                $snapshot->getDefinition($key); // Reject incompatible historical selections before any SQL runs.
            } else {
                $content = $this->entityManager->find('system' === $type ? SystemTemplate::class : Project::class, $contentId);
                $snapshot = $roots[$key] ??= $this->factory->capture($content);
            }
            $objectId = spl_object_id($snapshot);
            $snapshots[$objectId] ??= ['id' => count($snapshots) + 1, 'snapshot' => $snapshot];
            $positions[$id] = ['snapshot' => $snapshot, 'snapshot_id' => $snapshots[$objectId]['id'], 'key' => $key];
            unset($visiting[$id]);
        };
        foreach ($rows as $row) { $visit((int) $row['id']); }

        $queries = [];
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        foreach ($snapshots as $item) {
            /** @var ManufacturingSnapshot $snapshot */
            $snapshot = $item['snapshot'];
            $queries[] = new Query('INSERT INTO production_manufacturing_snapshots (id, definitions, datetime_added, last_modified) VALUES (?, ?, ?, ?)', [$item['id'], json_encode($snapshot->getDefinitions(), JSON_THROW_ON_ERROR), $now, $now]);
            foreach ($snapshot->getProjects() as $project) {
                $queries[] = new Query('INSERT INTO production_snapshot_projects (snapshot_id, project_id) VALUES (?, ?)', [$item['id'], $project->getId()]);
            }
            foreach ($snapshot->getParts() as $part) {
                $queries[] = new Query('INSERT INTO production_snapshot_parts (snapshot_id, part_id) VALUES (?, ?)', [$item['id'], $part->getId()]);
            }
        }
        foreach ($positions as $id => $item) {
            if (null !== $item) {
                $queries[] = new Query('UPDATE production_project_positions SET manufacturing_snapshot_id = ?, definition_key = ? WHERE id = ?', [$item['snapshot_id'], $item['key'], $id]);
            }
        }
        $queries[] = new Query('UPDATE production_project_positions SET source_slot_key = source_slot_id');
        $queries[] = new Query('UPDATE production_project_accessories SET source_slot_key = source_slot_id');
        $queries[] = new Query('UPDATE production_build_instances SET installed_slot_key = installed_slot_id');
        $queries[] = new Query('UPDATE production_build_instances SET manufacturing_snapshot_id = (SELECT manufacturing_snapshot_id FROM production_project_positions WHERE id = project_position_id), definition_key = (SELECT definition_key FROM production_project_positions WHERE id = project_position_id) WHERE project_position_id IS NOT NULL');
        return $queries;
    }
}
