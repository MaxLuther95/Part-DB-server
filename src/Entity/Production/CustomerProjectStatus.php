<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum CustomerProjectStatus: string
{
    case Planning = 'planning';
    case Commissioned = 'commissioned';
    case InProduction = 'in_production';
    case Completed = 'completed';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
