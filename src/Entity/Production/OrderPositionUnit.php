<?php

declare(strict_types=1);

namespace App\Entity\Production;

/** Units which may be used for commercial order positions. */
enum OrderPositionUnit: string
{
    case Piece = 'pcs.';
    case Set = 'set';

    public function getLabel(): string
    {
        return match ($this) {
            self::Piece => 'Stück (pcs.)',
            self::Set => 'Set (set)',
        };
    }

    public static function fromImportedValue(string $value): ?self
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            'pc', 'pc.', 'pcs', 'pcs.', 'piece', 'pieces', 'stk', 'stk.', 'stück' => self::Piece,
            'set', 'sets' => self::Set,
            default => null,
        };
    }
}
