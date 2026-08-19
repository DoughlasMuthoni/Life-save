<?php

namespace App\Domain\Goals\Enums;

enum AllocationEventType: string
{
    case ALLOCATE = 'allocate';
    case RELEASE = 'release';
}
