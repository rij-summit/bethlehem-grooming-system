<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClinicPreRegistrationRequest;
use App\Http\Requests\StoreClinicWalkinRequest;
use App\Models\ClinicAppointment;
use App\Models\ClinicClosure;
use App\Models\Pet;
use App\Models\TimeWindow;
use App\Models\User;
use App\Models\Walkin;
use App\Support\PetWeightSize;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClinicWalkinController extends Controller
{
    public function timeslots(Request $request)
    {
        $today = now()->toDateString();
        $lastAvailableDate = now()->addDays(2)->toDateString();
        $data = $request->validate([
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$today,
                'before_or_equal:'.$lastAvailableDate,
            ],
        ]);
        $appointmentDate = $data['date'];

        $windows = TimeWindow::query()
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->map(function (TimeWindow $window) use ($appointmentDate) {
                $booked = ClinicAppointment::query()
                    ->whereDate('appointment_date', $appointmentDate)
                    ->where('window_id', $window->window_id)
                    ->whereNotIn('status', ['cancelled', 'no_show'])
                    ->count();
                $capacity = max(1, (int) $window->max_slots);

                return [
                    'window_id' => $window->window_id,
                    'window_label' => $window->displayLabel(),
                    'start_time' => $window->start_time,
                    'end_time' => $window->end_time,
                    'max_slots' => $capacity,
                    'booked' => $booked,
                    'remaining' => max(0, $capacity - $booked),
                    'is_full' => $booked >= $capacity,
                    'is_past' => $this->windowHasStarted($appointmentDate, $window),
                    'recommended' => false,
                ];
            });

        $recommended = $windows
            ->filter(fn (array $window) => ! $window['is_full'] && ! $window['is_past'])
            ->sortBy([['booked', 'asc'], ['start_time', 'asc']])
            ->first();

        if ($recommended) {
            $windows = $windows->map(function (array $window) use ($recommended) {
                $window['recommended'] = $window['window_id'] === $recommended['window_id'];

                return $window;
            });
        }

        return response()->json([
            'success' => true,
            'date' => $appointmentDate,
            'day_full' => false,
            'windows' => $windows->values(),
        ]);
    }

    public function preRegister(StoreClinicPreRegistrationRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $user = $request->user();
            $appointmentDate = $data['appointment_date'];

            $closure = ClinicClosure::query()
                ->where('is_active', true)
                ->whereDate('start_date', '<=', $appointmentDate)
                ->whereDate('end_date', '>=', $appointmentDate)
                ->where(function ($query) use ($appointmentDate) {
                    $query->where('type', 'blocked_date')
                        ->orWhere(function ($stopToday) use ($appointmentDate) {
                            $stopToday
                                ->where('type', 'stop_today')
                                ->whereDate('start_date', now()->toDateString())
                                ->whereDate('start_date', $appointmentDate);
                        });
                })
                ->first();

            if ($closure) {
                return response()->json([
                    'success' => false,
                    'message' => $closure->reason ?: 'The clinic is not accepting visits on the selected date.',
                ], 422);
            }

            $window = TimeWindow::query()
                ->whereKey($data['window_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->windowHasStarted($appointmentDate, $window)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected clinic visit time has already passed.',
                ], 422);
            }

            $bookedInWindow = ClinicAppointment::query()
                ->whereDate('appointment_date', $appointmentDate)
                ->where('window_id', $window->window_id)
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->lockForUpdate()
                ->count();

            if ($bookedInWindow >= max(1, (int) $window->max_slots)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected clinic visit time is no longer available.',
                ], 422);
            }

            $pet = Pet::whereKey($data['pet_id'])
                ->where('user_id', $user->user_id)
                ->where('is_archived', false)
                ->firstOrFail();

            $duplicate = ClinicAppointment::query()
                ->whereDate('appointment_date', $appointmentDate)
                ->where('user_id', $user->user_id)
                ->where('pet_id', $pet->pet_id)
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => "{$pet->pet_name} already has a clinic visit registered on this date.",
                ], 422);
            }

            $reference = $this->nextAppointmentReference($appointmentDate);

            $appointment = ClinicAppointment::create([
                'appointment_reference' => $reference,
                'appointment_type' => 'pre_registered',
                'status' => 'waiting_to_arrive',
                'queue_number' => null,
                'appointment_date' => $appointmentDate,
                'window_id' => $window->window_id,
                'user_id' => $user->user_id,
                'walkin_id' => null,
                'pet_id' => $pet->pet_id,
                'chief_complaint' => $data['chief_complaint'],
                'total_amount' => 0,
                'paid' => false,
                'checked_in_at' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Clinic visit pre-registration submitted successfully.',
                'appointment' => [
                    'appointment_id' => $appointment->id,
                    'appointment_reference' => $appointment->appointment_reference,
                    'appointment_type' => $appointment->appointment_type,
                    'appointment_date' => $appointment->appointment_date->toDateString(),
                    'time_window' => [
                        'window_id' => $window->window_id,
                        'window_label' => $window->displayLabel(),
                        'start_time' => $window->start_time,
                        'end_time' => $window->end_time,
                    ],
                    'status' => $appointment->status,
                    'chief_complaint' => $appointment->chief_complaint,
                    'owner' => [
                        'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                        'email' => $user->email,
                        'phone' => $user->phone,
                    ],
                    'pet' => [
                        'pet_id' => $pet->pet_id,
                        'name' => $pet->pet_name,
                        'species' => $pet->species,
                        'breed' => $pet->breed,
                    ],
                ],
            ], 201);
        });
    }

    public function store(StoreClinicWalkinRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data = PetWeightSize::withComputedSize($data);

            $user = ! empty($data['email'])
                ? User::where('email', $data['email'])->first()
                : null;

            $walkin = Walkin::create([
                'fname' => $data['fname'],
                'lname' => $data['lname'],
                'mname' => $data['mname'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'sedation_consent' => false,
                'terms_agreed' => $data['terms_agreed'],
                'user_id' => $user?->user_id,
                'appointment_type' => 'clinic',
                'chief_complaint' => $data['chief_complaint'],
            ]);

            $pet = $this->findOrCreatePet($user, $data);

            // Atomically assign today's clinic queue number.
            $queueNumber = ((int) ClinicAppointment::where('appointment_date', now()->toDateString())
                ->lockForUpdate()
                ->max('queue_number')) + 1;

            $reference = $this->nextAppointmentReference(now()->toDateString());

            $appointment = ClinicAppointment::create([
                'appointment_reference' => $reference,
                'appointment_type' => 'walk_in',
                'status' => 'checked_in',
                'queue_number' => $queueNumber,
                'appointment_date' => now()->toDateString(),
                'user_id' => $user?->user_id,
                'walkin_id' => $walkin->id,
                'pet_id' => $pet->pet_id,
                'chief_complaint' => $data['chief_complaint'],
                'total_amount' => 0,
                'paid' => false,
                'checked_in_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'appointment_reference' => $reference,
                'queue_number' => $queueNumber,
                'appointment_id' => $appointment->id,
                'appointment_date' => $appointment->appointment_date->toDateString(),
                'status' => $appointment->status,
                'owner' => [
                    'name' => trim("{$walkin->fname} {$walkin->lname}"),
                    'email' => $walkin->email,
                    'phone' => $walkin->phone,
                ],
                'pet' => [
                    'name' => $pet->pet_name,
                    'species' => $pet->species,
                    'breed' => $pet->breed,
                ],
                'chief_complaint' => $walkin->chief_complaint,
                'returning_customer' => $user !== null,
            ], 201);
        });
    }

    private function nextAppointmentReference(string $appointmentDate): string
    {
        $date = Carbon::parse($appointmentDate);
        $prefix = 'CL-'.$date->format('Ymd').'-';
        $lastReference = ClinicAppointment::query()
            ->where('appointment_reference', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('appointment_reference')
            ->value('appointment_reference');
        $nextNumber = $lastReference
            ? ((int) substr($lastReference, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    private function windowHasStarted(string $appointmentDate, TimeWindow $window): bool
    {
        if ($appointmentDate !== now()->toDateString()) {
            return false;
        }

        $startTime = substr((string) $window->start_time, 0, 8);
        $startsAt = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            "{$appointmentDate} {$startTime}",
            config('app.timezone'),
        );

        return $startsAt->lessThanOrEqualTo(now());
    }

    private function findOrCreatePet(?User $user, array $data): Pet
    {
        if ($user) {
            $existing = Pet::where('user_id', $user->user_id)
                ->whereRaw('LOWER(pet_name) = ?', [strtolower($data['pet_name'])])
                ->first();

            if ($existing) {
                $existing->update([
                    'breed' => $data['breed'] ?? $existing->breed,
                    'fur_type' => $data['fur_type'] ?? $existing->fur_type,
                    'weight' => $data['weight'] ?? $existing->weight,
                    'size' => $data['size'] ?? $existing->size,
                ]);

                return $existing;
            }
        }

        return Pet::create([
            'user_id' => $user?->user_id,
            'pet_name' => $data['pet_name'],
            'species' => $data['species'],
            'breed' => $data['breed'] ?? null,
            'fur_type' => $data['fur_type'] ?? null,
            'weight' => $data['weight'] ?? null,
            'size' => $data['size'] ?? null,
            'medical_conditions' => $data['medical_conditions'] ?? null,
            'is_archived' => false,
        ]);
    }
}
