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
        return response()->json([
            'success' => true,
            'availability' => ClinicSetting::current()->availabilityPayload(),
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
            'pre_registration_cutoff_time' => ['required', 'date_format:H:i'],
        ]);

        $openTime = Carbon::createFromFormat('H:i', $data['open_time']);
        $closeTime = Carbon::createFromFormat('H:i', $data['close_time']);
        $cutoffTime = Carbon::createFromFormat('H:i', $data['pre_registration_cutoff_time']);

        if ($closeTime->lessThanOrEqualTo($openTime)) {
            throw ValidationException::withMessages([
                'close_time' => 'Closing time must be later than opening time.',
            ]);
        }

        if ($cutoffTime->lessThan($openTime) || $cutoffTime->greaterThan($closeTime)) {
            throw ValidationException::withMessages([
                'pre_registration_cutoff_time' => 'The pre-registration cutoff must be within operating hours.',
            ]);
        }

        $service = $data['service'];
        $settings = DB::transaction(function () use ($data, $service, $timeWindows) {
            $settings = ClinicSetting::current(lockForUpdate: true);
            $settings->update([
                "{$service}_open_time" => $data['open_time'],
                "{$service}_close_time" => $data['close_time'],
                "{$service}_prereg_cutoff_time" => $data['pre_registration_cutoff_time'],
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
        $data = $request->validate([
            'groomers_on_duty' => [
                'required',
                'integer',
                'min:'.ClinicSetting::MIN_GROOMERS_ON_DUTY,
                'max:'.ClinicSetting::MAX_GROOMERS_ON_DUTY,
            ],
        ]);

        $settings = DB::transaction(function () use ($data) {
            $settings = ClinicSetting::current(lockForUpdate: true);
            $settings->update([
                'groomers_on_duty' => $data['groomers_on_duty'],
            ]);

            return $settings->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Groomers on duty updated.',
            'groomers_on_duty' => $settings->groomers_on_duty,
        ]);
    }
}
