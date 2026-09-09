<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum ProtocolRunStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Invalid = 'invalid';
}
