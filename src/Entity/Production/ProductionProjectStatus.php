<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum ProductionProjectStatus: string
{
    case Planning = 'planning';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
