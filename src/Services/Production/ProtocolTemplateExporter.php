<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;

/** Exports definitions only; never traverse runs, users, orders or build instances. */
final class ProtocolTemplateExporter
{
    public const FORMAT = 'partdb.protocol-templates';
    public const FORMAT_VERSION = 1;
    public const MAX_REVISIONS = 100;

    /** @param list<ProtocolTemplateRevision> $revisions */
    public function export(array $revisions): string
    {
        if ([] === $revisions || count($revisions) > self::MAX_REVISIONS) {
            throw new \InvalidArgumentException('Select between 1 and 100 protocol template revisions.');
        }

        $templates = [];
        foreach ($revisions as $revision) {
            $template = $revision->getTemplate() ?? throw new \InvalidArgumentException('The revision must belong to a template.');
            // Object identity groups selections within this request only. It is never exported.
            // Names are editable and must not be used to merge distinct templates.
            $key = spl_object_id($template);
            if (! isset($templates[$key])) {
                $templates[$key] = [
                    'name' => $template->getName(),
                    'description' => $template->getDescription(),
                    'active' => $template->isActive(),
                    'revisions' => [],
                ];
            }
            $templates[$key]['revisions'][spl_object_id($revision)] = $this->revision($revision);
        }

        foreach ($templates as &$template) {
            $template['revisions'] = array_values($template['revisions']);
            usort($template['revisions'], static fn (array $left, array $right): int => $left['number'] <=> $right['number']);
        }
        unset($template);

        return json_encode([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'templates' => array_values($templates),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @return array<string, mixed> */
    private function revision(ProtocolTemplateRevision $revision): array
    {
        $sections = $revision->getSections()->toArray();
        usort($sections, static fn (ProtocolTemplateSection $left, ProtocolTemplateSection $right): int =>
            [$left->getPosition(), $left->getStableKey()] <=> [$right->getPosition(), $right->getStableKey()]);

        return [
            'number' => $revision->getRevisionNumber(),
            'status' => $revision->getStatus()->value,
            'change_note' => $revision->getChangeNote(),
            'published_at' => $revision->getPublishedAt()?->format(DATE_ATOM),
            'sections' => array_map($this->section(...), $sections),
        ];
    }

    /** @return array<string, mixed> */
    private function section(ProtocolTemplateSection $section): array
    {
        $fields = $section->getFields()->toArray();
        usort($fields, static fn (ProtocolTemplateField $left, ProtocolTemplateField $right): int =>
            [$left->getPosition(), $left->getStableKey()] <=> [$right->getPosition(), $right->getStableKey()]);

        return [
            'key' => $section->getStableKey(),
            'name' => $section->getName(),
            'description' => $section->getDescription(),
            'position' => $section->getPosition(),
            'fields' => array_map(static fn (ProtocolTemplateField $field): array => [
                'key' => $field->getStableKey(),
                'label' => $field->getLabel(),
                'type' => $field->getType()->value,
                'unit' => $field->getUnit(),
                'help_text' => $field->getHelpText(),
                'position' => $field->getPosition(),
                'layout_columns' => $field->getLayoutColumns(),
                'start_new_row' => $field->isStartNewRow(),
                'required' => $field->isRequired(),
                'options' => $field->getOptions(),
            ], $fields),
        ];
    }
}
