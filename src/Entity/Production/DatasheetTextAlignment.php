<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum DatasheetTextAlignment: string
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
}
