<?php

declare(strict_types=1);

namespace App\Enum;

enum ContactRequestStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case DECLINED = 'DECLINED';
}
