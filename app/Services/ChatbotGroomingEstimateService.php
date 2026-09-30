<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ChatbotGroomingEstimateService
{
    public function tool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'estimate_grooming_time',
                'description' => 'Use for grooming duration or finishing-time questions, including follow-ups giving size or haircut. Interpret typos, Taglish and recent conversation by meaning. Carry forward the chosen haircut and size from earlier USER messages unless corrected; a size-only reply never changes the haircut. For example, "How long for kalbo?" followed by "Large" means size=large, cut=short, difficult=false. Extract only customer-provided details; never assume difficult coat/handling from breed alone. Missing size or cut MUST be unknown: "I have a large huskey, how long will it take?" has size=large, cut=unknown, difficult=false. Never default to trim when no haircut was chosen. This returns the complete customer answer or next useful question using Bethlehem timings. Do not calculate your own duration.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'size' => [
                            'type' => 'string',
                            'enum' => ['unknown', 'small', 'medium', 'large', 'extra_large', 'giant'],
                            'description' => 'Use stated size, or stated weight with the supplied species size guide. Giant means explicitly very large/giant (including Giant Poodle); do not infer size just from breed.',
                        ],
                        'cut' => [
                            'type' => 'string',
                            'enum' => ['unknown', 'short', 'trim', 'styled', 'other'],
                            'description' => 'unknown is REQUIRED when no haircut/service is stated in the user messages. Grooming alone is unknown, not trim. short: Summer Cut, very short clipper cut, kalbo, bald/shave down. trim: Puppy Cut/regular trim. styled: Teddy/Bear, Poodle, Lion, breed-specific or special styled cut; these have no defined Bethlehem timing. other: bathing alone or other services without a defined timing.',
                        ],
                        'difficult' => [
                            'type' => 'boolean',
                            'description' => 'True only if customer explicitly reports substantial matting, unusually dense/long coat, difficult handling, stress/safety breaks, or substantial detailed trimming. A normal Husky alone is false.',
                        ],
                        'language' => ['type' => 'string', 'enum' => ['english', 'filipino']],
                    ],
                    'required' => ['size', 'cut', 'difficult', 'language'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function answer(array $details): string
    {
        $details = Validator::make($details, [
            'size' => ['required', Rule::in(['unknown', 'small', 'medium', 'large', 'extra_large', 'giant'])],
            'cut' => ['required', Rule::in(['unknown', 'short', 'trim', 'styled', 'other'])],
            'difficult' => ['required', 'boolean'],
            'language' => ['required', Rule::in(['english', 'filipino'])],
        ])->validate();

        $filipino = $details['language'] === 'filipino';
        $size = $details['size'];
        $cut = $details['cut'];

        if ($size === 'unknown' && $cut === 'unknown') {
            return $filipino
                ? 'Anong size ng alaga mo, at short/kalbo cut ba o trim tulad ng Puppy Cut ang gusto mo?'
                : 'What size is your pet, and are you planning a short/summer cut or a trim such as a Puppy Cut?';
        }

        if ($size === 'unknown') {
            $style = match ($cut) {
                'short' => 'For a short/kalbo cut, ',
                'trim' => 'For a Puppy Cut/regular trim, ',
                default => '',
            };

            return $filipino
                ? ($cut === 'short' ? 'Para sa short/kalbo cut, anong' : 'Anong').' size ng alaga mo — Small, Medium, Large, o Extra Large?'
                : $style.($style === '' ? 'What' : 'what').' size is your pet — Small, Medium, Large, or Extra Large?';
        }

        if ($cut === 'unknown') {
            return $filipino
                ? 'Short/summer cut ba o trim tulad ng Puppy Cut ang gusto mo?'
                : 'Are you planning a short/summer cut or a trim such as a Puppy Cut?';
        }

        if (in_array($cut, ['styled', 'other'], true)) {
            return $filipino
                ? 'Wala pang verified Bethlehem timing para sa style o service na iyan. Depende ito sa size, coat, at dami ng styling. Makipag-ugnayan sa clinic staff sa 7007-3122 o 0917-113-1941 para makumpirma ang estimate. Hiwalay ang paghihintay sa queue.'
                : 'Bethlehem has no verified timing for that style or service. The duration depends on size, coat, and the amount of styling. Contact clinic staff at 7007-3122 or 0917-113-1941 to confirm an estimate. Queue waiting time is separate.';
        }

        $large = in_array($size, ['large', 'extra_large', 'giant'], true);
        $difficult = (bool) $details['difficult'];
        $extendedTrim = $cut === 'trim' && $large && ($size === 'giant' || $difficult);
        $duration = match (true) {
            $cut === 'short' => 'around 30 minutes',
            $extendedTrim => 'around 2 to 3 hours',
            $large => 'around 2 hours',
            $difficult => 'around 1 hour to 1 hour 30 minutes',
            $size === 'medium' => 'around 1 hour and 30 minutes',
            default => 'around 1 hour',
        };

        // The supplied short-cut baseline has no approved numerical difficulty adjustment.
        $baselineOnly = $cut === 'short' && ($difficult || $size === 'giant');
        if ($filipino) {
            $primary = $baselineOnly
                ? "Ang straightforward short/kalbo cut ay **{$duration}**, pero maaaring mas matagal sa kondisyong nabanggit mo. Kailangang makita ng groomer ang pet para mas matantiya."
                : "Estimated grooming time: **{$duration}** para sa kumpletong service.";
            $qualification = $extendedTrim
                ? 'Sa mahirap na coat o handling, maaari itong umabot nang around 4 hours.'
                : 'Maaaring mas tumagal kung mahirap ang coat o handling.';

            return $primary."\n\n".$qualification.' Hiwalay ang waiting time sa queue; hindi ito garantisadong oras ng pickup.';
        }

        $primary = $baselineOnly
            ? "A straightforward short/summer cut takes **{$duration}**, but the condition you described may take longer. A groomer can assess your pet for a closer estimate."
            : "Estimated grooming time: **{$duration}** for the complete service.";
        $qualification = $extendedTrim
            ? 'Difficult coats or handling may take around 4 hours in some cases.'
            : 'Difficult coat or handling conditions may take longer.';

        return $primary."\n\n".$qualification.' Queue waiting time is separate; this is not a guaranteed pickup time.';
    }
}
