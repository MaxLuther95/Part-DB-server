<?php

declare(strict_types=1);

namespace App\Entity\Production;

enum DatasheetBlockType: string
{
    case Heading = 'heading';
    case StaticText = 'static_text';
    case EditableNote = 'editable_note';
    case Value = 'value';
    case ChildTable = 'child_table';
    case Separator = 'separator';
    case PageBreak = 'page_break';
}
