<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum ProtocolRevisionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
