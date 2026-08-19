<?php

namespace App\Domain\Goals\Enums;

enum GoalStatus: string
{
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case ABANDONED = 'abandoned';
}
