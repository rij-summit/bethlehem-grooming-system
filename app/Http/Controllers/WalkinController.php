<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWalkinRequest;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Models\Walkin;
use Illuminate\Support\Facades\DB;

class WalkinController extends Controller
{
    public function store(StoreWalkinRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();

            // Check if this email belongs to a registered customer
            $user = !empty($data['email'])
                ? User::where('email', $data['email'])->first()
                : null;

            // Resolve all services and prices upfront before any DB writes
            $resolvedPets = $this->resolveAllPets($data['pets']);
            $totalAmount  = array_sum(array_map(
                fn($p) => array_sum(array_column($p['services'], 'price')),
                $resolvedPets,
            ));

            // Create the walk-in record (owner info + consent)
            $walkin = Walkin::create([
                'fname'            => $data['fname'],
                'lname'            => $data['lname'],
                'mname'            => $data['mname'] ?? null,
                'email'            => $data['email'] ?? null,
                'phone'            => $data['phone'],
                'sedation_consent' => $data['sedation_consent'],
                'terms_agreed'     => $data['terms_agreed'],
                'user_id'          => $user?->user_id,
            ]);

            // Atomically assign today's queue number to prevent duplicates under concurrent requests
            $queueNumber = Booking::where('booking_type', 'walk_in')
                ->where('booking_date', now()->toDateString())
                ->lockForUpdate()
                ->count() + 1;

            $bookingReference = 'WI-' . now()->format('Ymd') . '-' . str_pad($queueNumber, 3, '0', STR_PAD_LEFT);

            // Create the booking — walk-in customers are already on-site so they
            // enter the queue immediately (checked_in) rather than waiting_to_arrive
            $booking = Booking::create([
                'booking_reference' => $bookingReference,
                'user_id'           => $user?->user_id,
                'walkin_id'         => $walkin->id,
                'booking_date'      => now()->toDateString(),
                'number_of_pets'    => count($resolvedPets),
                'booking_type'      => 'walk_in',
                'status'            => 'checked_in',
                'queue_number'      => $queueNumber,
                'total_amount'      => $totalAmount,
                'dropped_off_at'    => now(),
            ]);

            // For each pet: find or create a pet record, then attach services
            $petsResponse = [];
            foreach ($resolvedPets as $item) {
                $pet = $this->findOrCreatePet($user, $item['petData']);

                $bookingPet = BookingPet::create([
                    'booking_id'           => $booking->booking_id,
                    'pet_id'               => $pet->pet_id,
                    'special_instructions' => $item['petData']['special_instructions'] ?? null,
                ]);

                foreach ($item['services'] as $svc) {
                    BookingService::create([
                        'booking_id'       => $booking->booking_id,
                        'booking_pet_id'   => $bookingPet->booking_pet_id,
                        'service_id'       => $svc['service_id'],
                        'price_at_booking' => $svc['price'],
                    ]);
                }

                $petsResponse[] = [
                    'pet_name' => $item['petData']['pet_name'],
                    'species'  => $item['petData']['species'],
                    'size'     => $item['petData']['size'] ?? null,
                    'services' => array_map(fn($s) => [
                        'name'  => $s['name'],
                        'price' => $s['price'],
                    ], $item['services']),
                ];
            }

            return response()->json([
                'success'            => true,
                'booking_reference'  => $bookingReference,
                'queue_number'       => $queueNumber,
                'booking_id'         => $booking->booking_id,
                'booking_date'       => $booking->booking_date,
                'status'             => $booking->status,
                'owner'              => [
                    'name'  => trim("{$walkin->fname} {$walkin->lname}"),
                    'email' => $walkin->email,
                    'phone' => $walkin->phone,
                ],
                'pets'               => $petsResponse,
                'total_amount'       => $totalAmount,
                'returning_customer' => $user !== null,
            ], 201);
        });
    }

    private function resolveAllPets(array $pets): array
    {
        return array_map(function (array $petData) {
            return [
                'petData'  => $petData,
                'services' => $this->resolveServices($petData['services'], $petData['size'] ?? null),
            ];
        }, $pets);
    }

    private function resolveServices(array $services, ?string $size): array
    {
        return array_map(function (array $item) use ($size) {
            $service = Service::where('slug', $item['service_slug'])->firstOrFail();

            return [
                'service_id' => $service->service_id,
                'name'       => $service->service_name,
                'price'      => $this->resolvePrice($service, $size),
            ];
        }, $services);
    }

    private function resolvePrice(Service $service, ?string $size): float
    {
        return match ($size) {
            'small'                  => (float) ($service->price_small  ?? $service->base_price),
            'medium'                 => (float) ($service->price_medium ?? $service->base_price),
            'large', 'extra_large'   => (float) ($service->price_large  ?? $service->base_price),
            default                  => (float) $service->base_price,
        };
    }

    private function findOrCreatePet(?User $user, array $petData): Pet
    {
        // Try to match an existing pet by name for returning customers
        if ($user) {
            $existing = Pet::where('user_id', $user->user_id)
                ->whereRaw('LOWER(pet_name) = ?', [strtolower($petData['pet_name'])])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return Pet::create([
            'user_id'            => $user?->user_id,
            'pet_name'           => $petData['pet_name'],
            'species'            => $petData['species'],
            'breed'              => $petData['breed'] ?? null,
            'weight'             => $petData['weight'] ?? null,
            'size'               => $petData['size'] ?? null,
            'medical_conditions' => $petData['medical_conditions'] ?? null,
            'is_archived'        => false,
        ]);
    }
}
