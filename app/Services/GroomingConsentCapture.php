<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GroomingConsent;
use Closure;
use Illuminate\Validation\Rule;

class GroomingConsentCapture
{
    public static function agreement(): array
    {
        return json_decode(file_get_contents(base_path('scripts/data/grooming-consent.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function rules(): array
    {
        return [
            'terms_agreed' => ['required', 'accepted'],
            'sedation_consent' => ['required', 'boolean'],
            'signer_name' => ['required', 'string', 'max:200', 'not_regex:/^\s*$/u'],
            'consent_version' => ['required', Rule::in([self::agreement()['version']])],
            'signature_strokes' => ['required', 'array', 'min:1', 'max:100', function (string $attribute, mixed $strokes, Closure $fail) {
                // Require an actual stroke rather than a tap or a blank canvas.
                $length = 0;
                if (! is_array($strokes)) return;
                foreach ($strokes as $stroke) {
                    if (! is_array($stroke)) continue;
                    $previous = null;
                    foreach ($stroke as $point) {
                        if (! is_array($point) || count($point) !== 2 || ! is_numeric($point[0] ?? null) || ! is_numeric($point[1] ?? null)) continue;
                        if ($previous) $length += hypot($point[0] - $previous[0], $point[1] - $previous[1]);
                        $previous = $point;
                    }
                }
                if ($length < 3) $fail('Please draw your signature.');
            }],
            'signature_strokes.*' => ['required', 'array', 'min:2', 'max:1000'],
            'signature_strokes.*.*' => ['required', 'array', 'size:2'],
            'signature_strokes.*.*.0' => ['required', 'numeric', 'between:0,900'],
            'signature_strokes.*.*.1' => ['required', 'numeric', 'between:0,360'],
        ];
    }

    public static function capture(Booking $booking, array $data): GroomingConsent
    {
        $paths = '';
        foreach ($data['signature_strokes'] as $stroke) {
            $points = implode(' ', array_map(fn ($p) => round((float) $p[0], 2).','.round((float) $p[1], 2), $stroke));
            $paths .= '<polyline points="'.$points.'"/>';
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 360"><g fill="none" stroke="#1e293b" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">'.$paths.'</g></svg>';

        return GroomingConsent::create([
            'booking_id' => $booking->booking_id,
            'grooming_consent' => true,
            'sedation_consent' => (bool) $data['sedation_consent'],
            'signer_name' => trim($data['signer_name']),
            'signature_image' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'signature_strokes' => $data['signature_strokes'],
            'signed_at' => now(),
            'consent_version' => self::agreement()['version'],
            'agreement_snapshot' => self::agreement(),
        ]);
    }
}
