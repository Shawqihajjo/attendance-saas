<?php

declare(strict_types=1);

namespace App\Hardware;

enum DeviceAvailability: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Disconnected = 'disconnected';
}
