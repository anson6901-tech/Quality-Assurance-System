<?php

namespace App\Enums;

enum UniversityRole: string
{
    case QaOfficer = 'qa_officer';
    case QaDirector = 'qa_director';
    case IctAdmin = 'ict_admin';
    case Dean = 'dean';
    case Hod = 'hod';
    case Lecturer = 'lecturer';
    case AppointmentCommittee = 'appointment_committee';

    public function label(): string
    {
        return match ($this) {
            self::QaOfficer => 'QA Officer',
            self::QaDirector => 'QA Director',
            self::IctAdmin => 'ICT/System Administrator',
            self::Dean => 'Dean',
            self::Hod => 'Head of Department',
            self::Lecturer => 'Lecturer',
            self::AppointmentCommittee => 'Appointment & Promotion Committee',
        };
    }

    public function canManageQualityStructure(): bool
    {
        return in_array($this, [self::QaDirector, self::IctAdmin], true);
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
            [
                self::QaOfficer,
                self::QaDirector,
                self::IctAdmin,
                self::Dean,
                self::Hod,
                self::Lecturer,
                self::AppointmentCommittee,
            ],
        );
    }
}
