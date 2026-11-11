<?php

declare(strict_types=1);

namespace App\Enum;

enum GiftIdeaStatus: string
{
    case PROPOSED = 'PROPOSED';
    case SELECTED = 'SELECTED';
    case DISCARDED = 'DISCARDED';

    public function getSortWeight(): int
    {
        return match ($this) {
            self::SELECTED => 1,
            self::PROPOSED => 2,
            self::DISCARDED => 3,
        };
    }
}
