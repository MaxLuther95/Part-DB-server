<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum DatasheetFontFamily: string
{
    case SansSerif = 'sans_serif';
    case Serif = 'serif';
    case Monospace = 'monospace';
}
