<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum BuildStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';
    case Installed = 'installed';
    case Scrapped = 'scrapped';
}
