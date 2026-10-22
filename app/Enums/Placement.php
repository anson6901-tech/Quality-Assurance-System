<?php

namespace App\Enums;

/**
 * Where a user sits on the organizational structure. The role decides what a user can do;
 * the placement decides which part of the university they can see.
 */
enum Placement: string
{
    case University = 'university';
    case School = 'school';
    case Department = 'department';

    public function label(): string
    {
        return match ($this) {
            self::University => 'Whole university',
            self::School => 'School',
            self::Department => 'Department',
        };
    }
}
