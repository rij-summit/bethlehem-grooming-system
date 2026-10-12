<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class StaffRole
{
    public const TYPES = [
        'veterinarian' => 'clinic',
        'clinic_receptionist' => 'clinic',
        'grooming_receptionist' => 'grooming',
    ];

    public static function subrolesForType(string $type): array
    {
        return array_keys(self::TYPES, $type, true);
    }

    public static function fields(string $subrole): array
    {
        if (! isset(self::TYPES[$subrole])) {
            throw ValidationException::withMessages(['staff_subrole' => 'Choose a supported staff role.']);
        }

        return ['staff_type' => self::TYPES[$subrole], 'staff_subrole' => $subrole];
    }

    public static function assertValid(string $type, ?string $subrole): void
    {
        if ($subrole === null || (self::TYPES[$subrole] ?? null) !== $type) {
            throw ValidationException::withMessages([
                'staff_subrole' => 'Choose a staff role that matches the selected staff type.',
            ]);
        }
    }
}
