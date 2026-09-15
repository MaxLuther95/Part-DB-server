<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum OrderImportLineDisposition: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case Note = 'note';
}
