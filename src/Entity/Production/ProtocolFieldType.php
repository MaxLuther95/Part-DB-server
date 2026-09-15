<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum ProtocolFieldType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case TestResult = 'test_result';
    case Choice = 'choice';
    case StaticNote = 'static_note';

    public function getLabel(): string
    {
        return 'production.protocol.field_type.'.$this->value;
    }
}
