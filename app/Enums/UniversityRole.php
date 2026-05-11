<?php

namespace App\Enums;

enum UniversityRole: string
{
    case QaOfficer = 'qa_officer';
    case QaDirector = 'qa_director';
    case Dean = 'dean';
    case Hod = 'hod';
    case Lecturer = 'lecturer';

    public function label(): string
    {
        return match ($this) {
            self::QaOfficer => 'QA Officer',
            self::QaDirector => 'QA Director',
            self::Dean => 'Dean',
            self::Hod => 'Head of Department',
            self::Lecturer => 'Lecturer',
        };
    }

    public function canManageQualityStructure(): bool
    {
        return in_array($this, [self::QaOfficer, self::QaDirector], true);
    }

    public function canUploadReports(): bool
    {
        return in_array($this, [self::QaOfficer, self::QaDirector], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function userOptions(): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            [self::QaOfficer, self::QaDirector, self::Lecturer],
        );
    }
}
