<?php

namespace App\Services;

class ChatbotEmergencyService
{
    public function isActiveEmergency(string $message): bool
    {
        if ($this->isInformationalQuestion($message)) {
            return false;
        }

        foreach ([
            '/\b(?:can(?:not|\'t)|unable\s+to|difficulty|trouble)\s+(?:breathe|breathing)\b/iu',
            '/\b(?:choking|choked|not\s+breathing|stopped\s+breathing)\b/iu',
            '/\b(?:collapsed?|unconscious|unresponsive|passed\s+out)\b/iu',
            '/\b(?:seizure|seizing|convulsions?)\b/iu',
            '/\b(?:severe|heavy|won\'t\s+stop|cannot\s+stop)\s+bleeding\b/iu',
            '/\b(?:poisoned?|poisoning|ate\s+(?:rat\s+poison|chocolate|medicine)|toxic)\b/iu',
            '/\b(?:heatstroke|heat\s+stroke|overheating)\b/iu',
            '/\b(?:cannot|can\'t|unable\s+to)\s+(?:pee|urinate)\b/iu',
            '/\b(?:hindi|di)\s+(?:makahinga|humihinga)\b/iu',
            '/\b(?:nahihirapang|hirap)\s+(?:huminga|makahinga)\b/iu',
            '/\b(?:nabulunan|nawalan\s+ng\s+malay|kombulsyon|pangingisay|matinding\s+pagdurugo|nalason|heatstroke)\b/iu',
            '/\b(?:hindi|di)\s+(?:makaihi|umiihi)\b/iu',
        ] as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    public function reply(string $message): string
    {
        if ($this->prefersFilipino($message)) {
            return implode("\n\n", [
                'Maaaring **emergency** ito. Makipag-ugnayan agad sa veterinarian o sa pinakamalapit na emergency veterinary clinic.',
                'Huwag magbigay ng gamot o pasukahin ang alaga maliban kung direktang iniutos ng veterinarian. Kung ligtas, dalhin ang anumang packaging ng posibleng nakain o nalason.',
                'Bethlehem contact: 7007-3122 o 0917-113-1941. Hindi namin makukumpirma sa chat na may 24-hour emergency service ang clinic.',
            ]);
        }

        return implode("\n\n", [
            'This may be an **emergency**. Contact a veterinarian or the nearest emergency veterinary clinic immediately.',
            'Do not give medication or induce vomiting unless a veterinarian specifically instructs you. If safe, bring the packaging of anything the pet may have eaten or contacted.',
            'Bethlehem contact: 7007-3122 or 0917-113-1941. This chat cannot confirm that the clinic provides 24-hour emergency care.',
        ]);
    }

    private function isInformationalQuestion(string $message): bool
    {
        return preg_match(
            '/\b(?:what\s+are|which|list|signs?\s+of|does\s+(?:the\s+)?clinic|do\s+you\s+offer|when\s+should|ano\s+ang\s+mga|may\s+emergency\s+service)\b.*\b(?:emergency|warning|symptoms?|services?)\b/iu',
            $message
        ) === 1;
    }

    private function prefersFilipino(string $message): bool
    {
        return preg_match(
            '/\b(?:hindi|di|makahinga|nabulunan|nalason|alaga|aso|pusa|po|nawalan|pangingisay|makaihi)\b/iu',
            $message
        ) === 1;
    }
}
