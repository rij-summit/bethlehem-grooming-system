<?php

namespace App\Http\Controllers;

use App\Models\ClinicSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClinicSettingController extends Controller
{
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
