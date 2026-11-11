<?php

declare(strict_types=1);

namespace App\Enum;

enum WishPriority: int
{
    case LOW = 1;
    case NORMAL = 2;
    case HIGH = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::LOW => 'Pourquoi pas',
            self::NORMAL => 'Envie',
            self::HIGH => 'Coup de cœur',
        };
    }

    public function getBadgeClass(): string
    {
        return match ($this) {
            self::LOW => 'is-light',
            self::NORMAL => 'is-info is-light',
            self::HIGH => 'is-danger is-light',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::LOW => 'material-symbols:sentiment-satisfied',
            self::NORMAL => 'material-symbols:star',
            self::HIGH => 'material-symbols:favorite',
        };
    }
}
