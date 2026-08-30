<?php

namespace App\Services;

use Illuminate\Support\Str;

class ChatbotLanguageNormalizer
{
    /**
     * Common English roots used inside Filipino or Taglish word forms.
     * Longer roots come first so a specific word wins over its shorter stem.
     *
     * @var array<int, string>
     */
    private const CLINIC_LOANWORD_ROOTS = [
        'preregister',
        'registration',
        'confirmation',
        'consultation',
        'notification',
        'vaccination',
        'reschedule',
        'appointment',
        'availability',
        'verification',
        'reservation',
        'register',
        'schedule',
        'confirm',
        'consult',
        'vaccinate',
        'grooming',
        'booking',
        'payment',
        'sedation',
        'reserve',
        'adjust',
        'cancel',
        'checkin',
        'pickup',
        'verify',
        'vaccine',
        'groom',
        'queue',
        'notify',
        'sedate',
        'close',
        'open',
        'book',
        'bath',
        'price',
        'pay',
    ];

    /** @var array<int, string> */
    private const FILIPINO_PREFIXES = [
        'nakikipag',
        'makikipag',
        'pakikipag',
        'ipinapa',
        'nagpapa',
        'magpapa',
        'nakapag',
        'makapag',
        'pinapa',
        'ipina',
        'nagpa',
        'magpa',
        'nakaka',
        'makaka',
        'ipag',
        'naka',
        'maka',
        'pina',
        'naki',
        'maki',
        'nag',
        'mag',
        'pag',
        'ipa',
        'pin',
        'na',
        'ma',
        'ni',
        'pa',
        'i',
    ];

    /** @var array<int, string> */
    private const FILIPINO_SUFFIXES = [
        'han',
        'hin',
        'an',
        'in',
        'ng',
    ];

    public function normalizeForIntentMatching(string $message): string
    {
        $asciiMessage = mb_strtolower(Str::ascii($message));

        return preg_replace_callback(
            '/[a-z0-9]+/',
            fn (array $matches): string => $this->normalizeToken($matches[0]),
            $asciiMessage
        ) ?? $asciiMessage;
    }

    private function normalizeToken(string $word): string
    {
        foreach ($this->wordCandidates($word) as $candidate) {
            foreach (self::CLINIC_LOANWORD_ROOTS as $root) {
                if ($this->matchesRoot($candidate, $root)) {
                    return $root;
                }
            }
        }

        return $word;
    }

    /**
     * @return array<int, string>
     */
    private function wordCandidates(string $word): array
    {
        $candidates = [$word];

        for ($pass = 0; $pass < 3; $pass++) {
            foreach ($candidates as $candidate) {
                foreach (self::FILIPINO_PREFIXES as $prefix) {
                    if (
                        str_starts_with($candidate, $prefix)
                        && strlen($candidate) - strlen($prefix) >= 4
                    ) {
                        $candidates[] = substr($candidate, strlen($prefix));
                    }
                }

                foreach (self::FILIPINO_SUFFIXES as $suffix) {
                    if (
                        str_ends_with($candidate, $suffix)
                        && strlen($candidate) - strlen($suffix) >= 4
                    ) {
                        $candidates[] = substr($candidate, 0, -strlen($suffix));
                    }
                }
            }

            $candidates = array_values(array_unique($candidates));
        }

        return $candidates;
    }

    private function matchesRoot(string $candidate, string $root): bool
    {
        if ($this->isSameOrMinorTypo($candidate, $root)) {
            return true;
        }

        for ($syllableLength = 1; $syllableLength <= 3; $syllableLength++) {
            $repeatedPart = substr($root, 0, $syllableLength);

            if (! str_starts_with($candidate, $repeatedPart)) {
                continue;
            }

            $withoutRepeatedPart = substr($candidate, $syllableLength);

            if ($this->isSameOrMinorTypo($withoutRepeatedPart, $root)) {
                return true;
            }
        }

        return false;
    }

    private function isSameOrMinorTypo(string $candidate, string $root): bool
    {
        if ($candidate === $root) {
            return true;
        }

        if (strlen($root) < 5 || abs(strlen($candidate) - strlen($root)) > 1) {
            return false;
        }

        $isAdjacentTransposition = $this->isAdjacentTransposition(
            $candidate,
            $root
        );

        if (
            $candidate[0] !== $root[0]
            && ! $isAdjacentTransposition
            && ! $this->hasMissingOrExtraLeadingCharacter($candidate, $root)
        ) {
            return false;
        }

        return levenshtein($candidate, $root) <= 1
            || $isAdjacentTransposition;
    }

    private function isAdjacentTransposition(string $candidate, string $root): bool
    {
        if (strlen($candidate) !== strlen($root)) {
            return false;
        }

        $differences = [];

        for ($index = 0; $index < strlen($root); $index++) {
            if ($candidate[$index] !== $root[$index]) {
                $differences[] = $index;
            }
        }

        return count($differences) === 2
            && $differences[1] === $differences[0] + 1
            && $candidate[$differences[0]] === $root[$differences[1]]
            && $candidate[$differences[1]] === $root[$differences[0]];
    }

    private function hasMissingOrExtraLeadingCharacter(
        string $candidate,
        string $root,
    ): bool {
        if (strlen($candidate) + 1 === strlen($root)) {
            return $candidate === substr($root, 1);
        }

        if (strlen($root) + 1 === strlen($candidate)) {
            return $root === substr($candidate, 1);
        }

        return false;
    }
}
