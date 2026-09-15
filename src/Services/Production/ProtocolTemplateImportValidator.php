<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\ProtocolFieldType;
use Symfony\Component\Uid\Uuid;

/** Strict, bounded allow-list for untrusted version-1 definition files. */
final class ProtocolTemplateImportValidator
{
    public const MAX_BYTES = 2097152;

    /** @return array<string, mixed> */
    public function parse(string $json): array
    {
        $this->check(strlen($json) <= self::MAX_BYTES, 'File exceeds 2 MiB.');
        try {
            $root = json_decode($json, false, 20, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('Invalid JSON or excessive nesting.');
        }
        $this->object($root, ['format', 'format_version', 'exported_at', 'templates'], 'document');
        $this->check($root->format === ProtocolTemplateExporter::FORMAT && $root->format_version === 1, 'Unsupported export format or version.');
        $this->timestamp($root->exported_at, false, 'exported_at');
        $this->list($root->templates, 1, 100, 'templates');
        $revisionCount = 0;
        $fieldCount = 0;
        foreach ($root->templates as $ti => $template) {
            $path = 'templates['.$ti.']';
            $this->object($template, ['name', 'description', 'active', 'revisions'], $path);
            $this->text($template->name, 255, false, true, $path.'.name');
            $this->text($template->description, 65535, false, false, $path.'.description');
            $this->check(is_bool($template->active), $path.'.active must be boolean.');
            $this->list($template->revisions, 1, 100, $path.'.revisions');
            $numbers = [];
            foreach ($template->revisions as $revision) {
                $this->check(++$revisionCount <= 100, 'At most 100 revisions are allowed.');
                $this->object($revision, ['number', 'status', 'change_note', 'published_at', 'sections'], $path.'.revision');
                $this->integer($revision->number, 1, $path.'.revision.number');
                $this->unique($numbers, (string) $revision->number, 'Duplicate revision number.');
                $this->check(in_array($revision->status, ['draft', 'published', 'retired'], true), 'Unknown revision status.');
                $this->text($revision->change_note, 255, true, false, 'change_note');
                $this->timestamp($revision->published_at, true, 'published_at');
                $this->list($revision->sections, 0, 200, 'sections');
                $sectionKeys = [];
                $sectionPositions = [];
                foreach ($revision->sections as $section) {
                    $this->object($section, ['key', 'name', 'description', 'position', 'fields'], 'section');
                    $this->key($section->key, $sectionKeys);
                    $this->integer($section->position, 0, 'section.position');
                    $this->unique($sectionPositions, (string) $section->position, 'Duplicate section position.');
                    $this->text($section->name, 255, false, true, 'section.name');
                    $this->text($section->description, 65535, false, false, 'section.description');
                    $this->list($section->fields, 0, 500, 'fields');
                    $keys = [];
                    $positions = [];
                    foreach ($section->fields as $field) {
                        $this->check(++$fieldCount <= 5000, 'At most 5000 fields are allowed.');
                        $this->object($field, ['key', 'label', 'type', 'unit', 'help_text', 'position', 'layout_columns', 'start_new_row', 'required', 'options'], 'field');
                        $this->key($field->key, $keys);
                        $this->integer($field->position, 0, 'field.position');
                        $this->unique($positions, (string) $field->position, 'Duplicate field position.');
                        $this->text($field->label, 255, false, true, 'field.label');
                        $this->check(is_string($field->type) && null !== ProtocolFieldType::tryFrom($field->type), 'Unknown field type.');
                        $this->text($field->unit, 32, true, false, 'field.unit');
                        $this->text($field->help_text, 65535, true, false, 'field.help_text');
                        $this->check(in_array($field->layout_columns, [3, 6, 9, 12], true), 'Invalid field width.');
                        $this->check(is_bool($field->start_new_row) && is_bool($field->required), 'Field flags must be boolean.');
                        if (null !== $field->options) {
                            $this->list($field->options, 0, 200, 'options');
                            $options = [];
                            foreach ($field->options as $option) {
                                $this->text($option, 255, false, true, 'option');
                                $this->unique($options, trim($option), 'Duplicate choice option.');
                            }
                        }
                        if ('static_note' === $field->type) {
                            $this->check(false === $field->required && null === $field->unit && null === $field->options, 'Static notes cannot have input settings.');
                        }
                    }
                }
            }
        }

        return json_decode($json, true, 20, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $keys */
    private function object(mixed $value, array $keys, string $path): void
    {
        $this->check($value instanceof \stdClass, $path.' must be an object.');
        $actual = array_keys(get_object_vars($value));
        sort($actual);
        sort($keys);
        $this->check($actual === $keys, $path.' has missing or unknown properties.');
    }

    private function list(mixed $value, int $min, int $max, string $path): void
    {
        $this->check(is_array($value) && array_is_list($value) && count($value) >= $min && count($value) <= $max, $path.' has an invalid list size or type.');
    }

    private function text(mixed $value, int $max, bool $nullable, bool $notBlank, string $path): void
    {
        if ($nullable && null === $value) {
            return;
        }
        $this->check(is_string($value) && mb_strlen($value) <= $max && strlen($value) <= 65535, $path.' has an invalid text type or length.');
        $this->check(! $notBlank || '' !== trim($value), $path.' must not be blank.');
        $this->check(! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value), $path.' contains invalid control characters.');
    }

    private function integer(mixed $value, int $min, string $path): void
    {
        $this->check(is_int($value) && $value >= $min && $value <= 2147483647, $path.' must be a valid integer.');
    }

    private function timestamp(mixed $value, bool $nullable, string $path): void
    {
        if ($nullable && null === $value) {
            return;
        }
        $this->check(is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value), $path.' must be an ISO 8601 timestamp.');
        $date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        $this->check(false !== $date && $date->format(DATE_ATOM) === $value, $path.' must be an ISO 8601 timestamp.');
    }

    /** @param array<string|int, true> $seen */
    private function key(mixed $value, array &$seen): void
    {
        $this->check(is_string($value) && strlen($value) === 36 && Uuid::isValid($value), 'Invalid stable field or section key.');
        $this->unique($seen, strtolower($value), 'Duplicate stable key.');
    }

    /** @param array<string|int, true> $seen */
    private function unique(array &$seen, string $key, string $message): void
    {
        $this->check(! isset($seen[$key]), $message);
        $seen[$key] = true;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \DomainException($message);
        }
    }
}
