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
use App\Services\DailyPetQueue;
use App\Services\GroomingServicePriceResolver;
use App\Support\PetWeightSize;

class WalkinController extends Controller
{
    public function __construct(
        private readonly GroomingServicePriceResolver $servicePrices,
    ) {}

    public function store(StoreWalkinRequest $request)
    {
        $queueDate = now()->toDateString();

        return app(DailyPetQueue::class)->runForDate($queueDate, function () use ($request, $queueDate) {
            $data = $request->validated();
            $data['pets'] = array_map(
                fn (array $pet) => PetWeightSize::withComputedSize($pet),
                $data['pets'],
            );

            // Check if this email belongs to a registered customer
            $user = ! empty($data['email'])
                ? User::where('email', $data['email'])->first()
                : null;

            // Resolve all services and prices upfront before any DB writes
            $resolvedPets = $this->resolveAllPets($data['pets']);
            $totalAmount = array_sum(array_map(
                fn ($p) => array_sum(array_column($p['services'], 'price')),
                $resolvedPets,
            ));

            // Create the walk-in record (owner info + consent)
            $walkin = Walkin::create([
                'fname' => $data['fname'],
                'lname' => $data['lname'],
                'mname' => $data['mname'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'sedation_consent' => $data['sedation_consent'],
                'terms_agreed' => $data['terms_agreed'],
                'user_id' => $user?->user_id,
            ]);

            // Scheduled and walk-in owners share the same daily queue.
            $queueNumber = ((int) Booking::where('booking_date', $queueDate)
                ->whereNotNull('queue_number')
                ->max('queue_number')) + 1;

            $bookingReference = 'WI-'.str_replace('-', '', $queueDate).'-'.str_pad($queueNumber, 3, '0', STR_PAD_LEFT);

            // Create the booking — walk-in customers are already on-site so they
            // enter the queue immediately (checked_in) rather than waiting_to_arrive
            $booking = Booking::create([
                'booking_reference' => $bookingReference,
                'user_id' => $user?->user_id,
                'walkin_id' => $walkin->id,
                'booking_date' => $queueDate,
                'number_of_pets' => count($resolvedPets),
                'booking_type' => 'walk_in',
                'status' => 'checked_in',
                'queue_number' => $queueNumber,
                'total_amount' => $totalAmount,
                'dropped_off_at' => now(),
            ]);

            // For each pet: find or create a pet record, then attach services
            $petsResponse = [];
            foreach ($resolvedPets as $item) {
                $pet = $this->findOrCreatePet($user, $item['petData']);

                $bookingPet = BookingPet::create([
                    'booking_id' => $booking->booking_id,
                    'pet_id' => $pet->pet_id,
                    'special_instructions' => $item['petData']['special_instructions'] ?? null,
                ]);

                foreach ($item['services'] as $svc) {
                    BookingService::create([
                        'booking_id' => $booking->booking_id,
                        'booking_pet_id' => $bookingPet->booking_pet_id,
                        'service_id' => $svc['service_id'],
                        'price_at_booking' => $svc['price'],
                    ]);
                }

                $petsResponse[] = [
                    'booking_pet_id' => $bookingPet->booking_pet_id,
                    'pet_name' => $item['petData']['pet_name'],
                    'species' => $item['petData']['species'],
                    'size' => $item['petData']['size'] ?? null,
                    'services' => array_map(fn ($s) => [
                        'name' => $s['name'],
                        'price' => $s['price'],
                    ], $item['services']),
                ];
            }

            $petQueueNumbers = app(DailyPetQueue::class)->assignBookingPets($booking, $queueDate);
            foreach ($petsResponse as &$petResponse) {
                $petResponse['pet_queue_number'] = $petQueueNumbers[$petResponse['booking_pet_id']];
            }
            unset($petResponse);

            return response()->json([
                'success' => true,
                'booking_reference' => $bookingReference,
                'queue_number' => $queueNumber,
                'booking_id' => $booking->booking_id,
                'booking_date' => $booking->booking_date,
                'status' => $booking->status,
                'owner' => [
                    'name' => trim("{$walkin->fname} {$walkin->lname}"),
                    'email' => $walkin->email,
                    'phone' => $walkin->phone,
                ],
                'pets' => $petsResponse,
                'total_amount' => $totalAmount,
                'returning_customer' => $user !== null,
            ], 201);
        });
    }

    private function resolveAllPets(array $pets): array
    {
        return array_map(function (array $petData) {
            return [
                'petData' => $petData,
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
                'name' => $service->service_name,
                'price' => $this->resolvePrice($service, $size),
            ];
        }, $services);
    }

    private function resolvePrice(Service $service, ?string $size): float
    {
        return (float) $this->servicePrices->servicePrice($service, $size);
    }

    private function findOrCreatePet(?User $user, array $petData): Pet
    {
        // Try to match an existing pet by name for returning customers
        if ($user) {
            $existing = Pet::where('user_id', $user->user_id)
                ->whereRaw('LOWER(pet_name) = ?', [strtolower($petData['pet_name'])])
                ->first();

            if ($existing) {
                $existing->update([
                    'breed' => $petData['breed'] ?? $existing->breed,
                    'fur_type' => $petData['fur_type'] ?? $existing->fur_type,
                    'weight' => $petData['weight'] ?? $existing->weight,
                    'size' => $petData['size'] ?? $existing->size,
                ]);

                return $existing;
            }
        }

        return Pet::create([
            'user_id' => $user?->user_id,
            'pet_name' => $petData['pet_name'],
            'species' => $petData['species'],
            'breed' => $petData['breed'] ?? null,
            'fur_type' => $petData['fur_type'] ?? null,
            'weight' => $petData['weight'] ?? null,
            'size' => $petData['size'] ?? null,
            'medical_conditions' => $petData['medical_conditions'] ?? null,
            'is_archived' => false,
        ]);
    }
}
