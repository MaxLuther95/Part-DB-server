<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Services\Production\ProtocolTemplateImportValidator;
use App\Tests\Fixtures\ProtocolTemplateImportExample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtocolTemplateImportValidatorTest extends TestCase
{
    public function testCurrentExportIsAccepted(): void
    {
        $json = ProtocolTemplateImportExample::json();
        self::assertSame(json_decode($json, true), (new ProtocolTemplateImportValidator())->parse($json));
    }

    #[DataProvider('invalidFiles')]
    public function testMalformedOrUnsupportedFilesAreRejected(string $json): void
    {
        $this->expectException(\DomainException::class);
        (new ProtocolTemplateImportValidator())->parse($json);
    }

    public static function invalidFiles(): iterable
    {
        yield 'invalid JSON' => ['{'];
        yield 'array root' => ['[]'];
        yield 'oversize' => [str_repeat(' ', ProtocolTemplateImportValidator::MAX_BYTES + 1)];
        $base = json_decode(ProtocolTemplateImportExample::json(), true);
        $changes = [
            'unknown property' => static function (array &$data): void { $data['execute'] = 'file:///etc/passwd'; },
            'version string' => static function (array &$data): void { $data['format_version'] = '1'; },
            'wrong format' => static function (array &$data): void { $data['format'] = 'other'; },
            'invalid timestamp' => static function (array &$data): void { $data['exported_at'] = "2026\0"; },
            'invalid date' => static function (array &$data): void { $data['exported_at'] = '2026-02-31T12:00:00+00:00'; },
            'empty templates' => static function (array &$data): void { $data['templates'] = []; },
            'bad bool' => static function (array &$data): void { $data['templates'][0]['active'] = 'true'; },
            'unknown field type' => static function (array &$data): void { $data['templates'][0]['revisions'][0]['sections'][0]['fields'][0]['type'] = 'executable'; },
            'duplicate revision' => static function (array &$data): void { $data['templates'][0]['revisions'][] = $data['templates'][0]['revisions'][0]; },
            'duplicate section' => static function (array &$data): void { $data['templates'][0]['revisions'][0]['sections'][] = $data['templates'][0]['revisions'][0]['sections'][0]; },
            'duplicate field' => static function (array &$data): void { $data['templates'][0]['revisions'][0]['sections'][0]['fields'][] = $data['templates'][0]['revisions'][0]['sections'][0]['fields'][0]; },
            'bad field width' => static function (array &$data): void { $data['templates'][0]['revisions'][0]['sections'][0]['fields'][0]['layout_columns'] = 5; },
            'bad UUID' => static function (array &$data): void { $data['templates'][0]['revisions'][0]['sections'][0]['key'] = '../file'; },
            'negative position' => static function (array &$data): void { $data['templates'][0]['revisions'][0]['sections'][0]['position'] = -1; },
            'oversize name' => static function (array &$data): void { $data['templates'][0]['name'] = str_repeat('x', 256); },
            'object instead of list' => static function (array &$data): void { $data['templates'][0]['revisions'][0]['sections'] = new \stdClass(); },
            'measurements not allowed' => static function (array &$data): void { $data['templates'][0]['revisions'][0]['answers'] = []; },
        ];
        foreach ($changes as $label => $change) {
            $data = $base;
            $change($data);
            yield $label => [json_encode($data, JSON_THROW_ON_ERROR)];
        }
    }
}
