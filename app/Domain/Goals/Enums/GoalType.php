<?php

namespace App\Domain\Goals\Enums;

/**
 * Only SAVINGS has real behavior wired up in V1 (CLAUDE.md §GOALS: "use a
 * common goal structure, but do not build an overly abstract generic goal
 * engine"). WEIGHT/FITNESS/PERSONAL/PROJECT/CUSTOM are named in CLAUDE.md
 * as possible future types — add the case when the phase that needs it
 * actually lands, not before.
 */
enum GoalType: string
{
    case SAVINGS = 'savings';
}
