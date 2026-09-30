<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ChatbotKnowledgeService
{
    public function visitProcess(string $language = 'english'): string
    {
        return $language === 'filipino'
            ? 'Oo, **tumatanggap ng walk-ins** ang Bethlehem para sa clinic at grooming habang bukas at may capacity. Wala kaming appointments o reserved service slots. Maaari kang mag-pre-register online para maipadala nang maaga ang impormasyon mo at ng alaga mo. Hindi nito nirereserba ang queue number, grooming start time, o guaranteed service time. Sasali lang sa queue ang pet pagkatapos mong dumating at matagumpay na ma-check in ng staff.'
            : 'We do not use appointments or reserved service slots. Bethlehem **accepts walk-ins** for clinic and grooming while open and capacity is available. You can pre-register online to send your and your pet\'s information ahead. This does not reserve a queue number, grooming start time, or guaranteed service time. Your pet joins the queue only after you arrive and staff successfully completes check-in.';
    }

    /** Customer labels and capabilities verified against pages/client and their scripts. */
    public function customerGuides(): array
    {
        return [
            'account' => implode("\n", [
                '1. Open "Sign In" from the home page, then "Sign Up".',
                '2. Enter your first and last name, username, phone number, email, password and password confirmation in the form.',
                '3. Accept the terms and select "Create Account".',
                '4. Check your registered email for Bethlehem\'s verification email.',
                '5. Open and use the verification link sent by email to activate the account.',
                '6. Return to "Sign In" after verification.',
                '',
                'If needed, use "Resend Verification Email" on the verification page. Do not send passwords or verification codes in this chat.',
            ]),
            'signin' => 'Open "Sign In" from the home page and sign in with your customer account after email verification.',
            'password' => 'On "Sign In", choose "Forgot password?", enter your account email on that page, enter the emailed verification code there, create a new password, and sign in again. Never share the password or code in chat. Password reset uses email, not SMS.',
            'pet' => 'After signing in, use Dashboard > "Quick Actions" > "Add Pet", or open "My Pets" and select "Add Pet". Complete the pet details in the form and select "Save Pet". Existing pet information is available in "My Pets".',
            'preregister' => implode("\n\n", [
                'Sign in, open the Dashboard and click "Pre-register", then choose "Grooming" or "Clinic Visit". Choose an available arrival date/window and your pet.',
                'For Grooming, choose the services, review the details, complete the required grooming consent and digital signature (sedation consent is optional), then click "Submit Registration".',
                'For Clinic Visit, provide the reason for the visit, review the summary, and click "Submit Pre-registration".',
                'This sends your information ahead; it does not reserve a queue number, appointment, grooming start time, or guaranteed service time. Your pet joins the queue only after arrival and successful staff check-in.',
            ]),
            'schedules' => 'Open the Dashboard and find "Schedules" to review upcoming grooming pre-registrations. For a clinic visit record that is not shown there, refer to your clinic visit confirmation or ask clinic staff. I can also check your own schedule when you are signed in.',
            'tracker' => 'After signing in, open the Dashboard and scroll to "Grooming Tracker" to see progress for pets checked in at the clinic, including waiting, grooming, and pickup progress. It is a progress tracker, not a live video feed.',
            'history' => 'Open "Grooming History" from the customer menu to see previous grooming visits. The Dashboard also has a "Grooming History" section.',
            'settings' => 'Open "Settings" > "Account" to view "Personal Information". The current name, phone and email fields are read-only; there is no customer save/edit action for these fields. Contact clinic staff at 7007-3122 or 0917-113-1941 to request a correction. For a forgotten password, use "Forgot password?" on "Sign In".',
            'changes' => 'Open "Schedules" on the Dashboard. An upcoming grooming pre-registration that is still waiting for arrival has "Reschedule" and "Cancel" buttons. For "Reschedule", choose a new arrival date/window and click "Confirm Reschedule". For "Cancel", confirm with "Cancel" in "Cancel Pre-registration". These change the planned arrival, not a reserved service time. If those buttons are unavailable, or you need to change a Clinic Visit, contact clinic staff at 7007-3122 or 0917-113-1941.',
            'notifications' => 'Select the "Notifications" bell at the top of a customer page. It shows your updates; select "See all notifications" to open the notifications page. When grooming is finished, a ready-for-pickup notification is also sent by email.',
        ];
    }

    public function customerGuideTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'customer_guide',
                'description' => 'REQUIRED for website/account help. Returns verified steps with actual current labels, not a record lookup. Select topic by meaning: account=create account/Sign Up/email verification; signin=where to sign in; password=forgot/reset password; pet=add/edit pet; preregister=how to pre-register or I do not know how; schedules=where to see pre-registrations; tracker=where to see grooming progress; history=previous grooming; settings=change personal/account information; changes=cancel/reschedule; notifications=find notifications. Do not invent steps or ask which service before providing the preregister guide. Use recent context for follow-ups. Returns the final customer answer.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'topic' => ['type' => 'string', 'enum' => array_keys($this->customerGuides())],
                        'language' => ['type' => 'string', 'enum' => ['english', 'filipino']],
                    ],
                    'required' => ['topic', 'language'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function customerGuide(array $arguments): string
    {
        $guides = $this->customerGuides();
        $arguments = Validator::make($arguments, [
            'topic' => ['required', Rule::in(array_keys($guides))],
            'language' => ['required', 'in:english,filipino'],
        ])->validate();

        if ($arguments['language'] === 'english') {
            return $guides[$arguments['topic']];
        }

        return match ($arguments['topic']) {
            'account' => 'Buksan ang "Sign In" sa home page, tapos "Sign Up". Ilagay sa form ang first at last name, username, phone number, email, password at password confirmation. Tanggapin ang terms at piliin ang "Create Account". Buksan ang verification link sa email mo para ma-activate ang account, saka bumalik sa "Sign In". Kung kailangan, piliin ang "Resend Verification Email" sa verification page. Huwag ipadala ang password o verification code dito sa chat.',
            'signin' => 'Piliin ang "Sign In" sa home page at gamitin ang customer account mo pagkatapos ng email verification.',
            'password' => 'Sa "Sign In", piliin ang "Forgot password?". Ilagay doon ang account email mo, gamitin sa page ang code na ipinadala sa email, gumawa ng bagong password, at mag-sign in ulit. Email ang gamit, hindi SMS. Huwag ibahagi dito ang password o code.',
            'pet' => 'Pagka-sign in, pumunta sa Dashboard > "Quick Actions" > "Add Pet", o buksan ang "My Pets" at piliin ang "Add Pet". Kumpletuhin ang pet details at piliin ang "Save Pet". Makikita rin sa "My Pets" ang existing pet information.',
            'preregister' => 'Mag-sign in, buksan ang Dashboard, piliin ang "Pre-register", at pumili ng "Grooming" o "Clinic Visit". Piliin ang available arrival date/window at ang pet mo.

Para sa Grooming, piliin ang services, i-review ang details, kumpletuhin ang grooming consent at digital signature (optional ang sedation consent), at piliin ang "Submit Registration". Para sa Clinic Visit, ilagay ang dahilan ng pagbisita, i-review ang summary, at piliin ang "Submit Pre-registration".

Ipinapadala lang nito nang maaga ang impormasyon mo. Hindi nito nirereserba ang queue number o service time. Sasali lang sa queue ang pet matapos kang dumating at matagumpay na ma-check in ng staff.',
            'schedules' => 'Sa Dashboard, hanapin ang "Schedules" para makita ang upcoming grooming pre-registrations. Kung hindi nakikita roon ang Clinic Visit, tingnan ang clinic visit confirmation o magtanong sa staff. Maaari ko ring i-check ang sarili mong schedule kapag naka-sign in ka.',
            'tracker' => 'Pagka-sign in, buksan ang Dashboard at mag-scroll sa "Grooming Tracker". Makikita roon ang progress ng pets na na-check in na, gaya ng paghihintay, grooming, at pickup. Progress tracker ito, hindi live video.',
            'history' => 'Piliin ang "Grooming History" sa customer menu para makita ang mga nakaraang grooming visit. May "Grooming History" section din sa Dashboard.',
            'settings' => 'Sa "Settings" > "Account", makikita ang "Personal Information". Read-only sa kasalukuyan ang pangalan, phone at email; walang customer edit/save action para rito. Para magpa-correct, tawagan ang clinic staff sa 7007-3122 o 0917-113-1941. Kung nakalimutan ang password, gamitin ang "Forgot password?" sa "Sign In".',
            'changes' => 'Buksan ang "Schedules" sa Dashboard. May "Reschedule" at "Cancel" ang upcoming grooming pre-registration habang hindi pa dumarating ang pet. Para mag-reschedule, pumili ng bagong arrival date/window at piliin ang "Confirm Reschedule". Para mag-cancel, piliin ang "Cancel" sa "Cancel Pre-registration". Arrival lang ang binabago, hindi reserved service time. Kung walang buttons o Clinic Visit ang babaguhin, tawagan ang staff sa 7007-3122 o 0917-113-1941.',
            'notifications' => 'Piliin ang "Notifications" bell sa itaas ng customer page, tapos "See all notifications" para sa lahat ng updates. Kapag tapos na ang grooming, may ready-for-pickup notification din sa email.',
        };
    }

    /**
     * @return array<int, string>
     */
    public function groomingCatalogPromptLines(): array
    {
        if (! Schema::hasTable('services')) {
            return $this->fallbackCatalogLines();
        }

        $services = Service::query()
            ->where('is_active', true)
            ->orderBy('service_id')
            ->get();

        if ($services->isEmpty()) {
            return $this->fallbackCatalogLines();
        }

        $packages = $services
            ->filter(fn (Service $service) => $this->kind($service) === 'package')
            ->map(fn (Service $service) => $this->packageLine($service))
            ->filter()
            ->values();
        $alaCarte = $services
            ->filter(fn (Service $service) => $this->kind($service) === 'ala_carte')
            ->map(fn (Service $service) => $this->alaCarteItem($service))
            ->filter()
            ->values();

        $lines = $packages->map(fn (string $line) => '- '.$line)->all();

        if ($alaCarte->isNotEmpty()) {
            $lines[] = '- A la carte: '.$alaCarte->implode('; ').'.';
        }

        $lines[] = '- A plus sign means the listed amount is a starting price. The final price is determined at the clinic.';
        $lines[] = '- This catalogue is loaded from the current active services records. Do not quote inactive services or invent other prices.';

        return $lines;
    }

    public function conciseCatalog(): string
    {
        return collect($this->groomingCatalogPromptLines())
            ->slice(0, -2)
            ->implode("\n");
    }

    private function packageLine(Service $service): ?string
    {
        $prices = collect([
            'Small' => $this->packagePrice($service, 'small'),
            'Medium' => $this->packagePrice($service, 'medium'),
            'Large' => $this->packagePrice($service, 'large'),
            'Extra Large' => $this->packagePrice($service, 'extra_large'),
        ])->filter(fn ($price) => $price !== null);

        if ($prices->isEmpty()) {
            return null;
        }

        $isDogPackage = $service->slug !== 'cat_full_grooming';
        $species = $isDogPackage ? 'dog' : 'cat';
        $description = trim((string) $service->description);
        $details = $description !== '' ? '; '.$description : '';
        $priceText = $prices->map(function (float $price, string $size) use (
            $isDogPackage
        ) {
            $starting = $isDogPackage
                && in_array($size, ['Large', 'Extra Large'], true);

            return $size.' PHP '.$this->formatAmount($price).($starting ? '+' : '');
        })->implode('; ');

        return "{$service->service_name} ({$species}{$details}): {$priceText}.";
    }

    private function alaCarteItem(Service $service): ?string
    {
        $minimum = $this->columnAmount($service, 'price_min');
        $maximum = $this->columnAmount($service, 'price_max');
        $base = $this->positiveAmount($service->base_price);

        if ($minimum !== null && $maximum !== null) {
            return $service->service_name.' PHP '
                .$this->formatAmount($minimum).'-'.$this->formatAmount($maximum);
        }

        if ($base === null) {
            return null;
        }

        $starting = Schema::hasColumn('services', 'is_starting_price')
            && (bool) $service->is_starting_price;

        return $service->service_name.' PHP '
            .$this->formatAmount($base).($starting ? '+' : '');
    }

    private function packagePrice(Service $service, string $size): ?float
    {
        $column = match ($size) {
            'small' => 'price_small',
            'medium' => 'price_medium',
            'large' => 'price_large',
            'extra_large' => 'price_extra_large',
        };
        $databasePrice = $this->columnAmount($service, $column);

        if ($databasePrice !== null) {
            return $databasePrice;
        }

        return $this->positiveAmount(
            config("grooming_services.services.{$service->slug}.prices.{$size}")
        );
    }

    private function columnAmount(Service $service, string $column): ?float
    {
        if (! Schema::hasColumn('services', $column)) {
            return null;
        }

        return $this->positiveAmount($service->{$column});
    }

    private function positiveAmount(mixed $amount): ?float
    {
        return is_numeric($amount) && (float) $amount > 0
            ? (float) $amount
            : null;
    }

    private function kind(Service $service): ?string
    {
        return config("grooming_services.services.{$service->slug}.kind");
    }

    private function formatAmount(float $amount): string
    {
        return fmod($amount, 1.0) === 0.0
            ? number_format($amount, 0)
            : number_format($amount, 2);
    }

    /**
     * @return array<int, string>
     */
    private function fallbackCatalogLines(): array
    {
        return [
            '- Partial Grooming (dog; trimming, nail clipping, ear cleaning, dry shampoo): Small PHP 400; Medium PHP 500; Large PHP 600+; Extra Large PHP 700+.',
            '- Regular Dog Grooming (bath, blow dry, haircut, nail clipping, ear cleaning, tooth brushing): Small PHP 550; Medium PHP 650; Large PHP 850+; Extra Large PHP 1,050+.',
            '- Deluxe Dog Grooming (bath, blow dry, special haircut, nail clipping, ear cleaning, tooth brushing): Small PHP 650; Medium PHP 750; Large PHP 1,000+; Extra Large PHP 1,200+.',
            '- Bath and Go (dog; bath, blow dry, nail clipping, ear cleaning, tooth brushing): Small PHP 450; Medium PHP 550; Large PHP 650+; Extra Large PHP 750+.',
            '- Full Grooming (cat; bath, blow dry, optional haircut, nail clipping, ear cleaning): Small PHP 500; Medium PHP 600.',
            '- A la carte: Nail Clipping PHP 50-100; Ear Cleaning PHP 150+; Facial Trimming PHP 150; Anal Sac Draining PHP 150; Tooth Brushing PHP 100+.',
            '- A plus sign means the listed amount is a starting price. The final price is determined at the clinic.',
            '- Do not invent discounts, other packages, or other prices.',
        ];
    }
}
