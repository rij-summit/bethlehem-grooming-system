<?php

namespace App\Http\Controllers;

use App\Models\ClinicSetting;
use App\Services\AvailabilityTimeWindowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicSettingController extends Controller
{
    public function availability()
    {
        $settings = ClinicSetting::current();

        return response()->json([
            'success' => true,
            'availability' => $settings->availabilityPayload(),
            'groomers_on_duty' => $settings->groomers_on_duty,
            'pet_groomers' => \App\Models\PetGroomer::orderBy('id')->get(),
        ]);
    }

    public function updateAvailability(
        Request $request,
        AvailabilityTimeWindowService $timeWindows,
    )
    {
        $data = $request->validate([
            'service' => ['required', Rule::in(['clinic', 'grooming'])],
            'open_time' => ['required', 'date_format:H:i'],
            'close_time' => ['required', 'date_format:H:i'],
        ]);

        $openTime = Carbon::createFromFormat('H:i', $data['open_time']);
        $closeTime = Carbon::createFromFormat('H:i', $data['close_time']);

        if ($closeTime->lessThanOrEqualTo($openTime)) {
            throw ValidationException::withMessages([
                'close_time' => 'Closing time must be later than opening time.',
            ]);
        }

        $service = $data['service'];
        $settings = DB::transaction(function () use ($data, $service, $timeWindows) {
            $settings = ClinicSetting::current(lockForUpdate: true);
            $settings->update([
                "{$service}_open_time" => $data['open_time'],
                "{$service}_close_time" => $data['close_time'],
            ]);
            $settings = $settings->fresh();
            $timeWindows->sync($settings);

            return $settings;
        });

        return response()->json([
            'success' => true,
            'message' => ucfirst($service).' availability updated.',
            'availability' => $settings->availabilityPayload(),
        ]);
    }

    public function updateGroomersOnDuty(Request $request)
    {
        throw ValidationException::withMessages([
            'groomers_on_duty' => 'Update Pet Groomer duty states in Availability settings.',
        ]);
    }
}
