<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum DatasheetTextSize: string
{
    case Small = 'small';
    case Normal = 'normal';
    case Large = 'large';
    case ExtraLarge = 'extra_large';
}
