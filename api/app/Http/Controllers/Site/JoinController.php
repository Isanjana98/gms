<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RegistrationQuestion;
use App\Services\HealthScreeningService;
use App\Services\MemberRegistrationService;
use App\Support\GymSettings;
use App\Support\ParQ;
use App\Support\RegistrationForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Self-registration from the QR code printed at the desk.
 *
 * Unlike the health form, there is no per-person token here: the whole point
 * is one code that anyone can scan. The branch code in the URL says which gym
 * is being joined and nothing more - it is not a secret, and nothing is
 * granted by holding it.
 *
 * What protects this is on the other side. A submission creates a pending
 * registration and nothing else: no member, no membership number, no access
 * to anything. Someone at the desk has to approve it before it means a thing.
 */
class JoinController extends Controller
{
    public function __construct(
        private readonly MemberRegistrationService $registrations,
        private readonly HealthScreeningService $screenings,
        private readonly GymSettings $settings,
    ) {}

    private function gymName(): string
    {
        return (string) $this->settings->get('branding.gym_name', null, config('app.name'));
    }

    /**
     * The branch whose code was scanned.
     *
     * A 404 rather than a redirect to some default: a printed code that no
     * longer matches a branch is a poster that needs replacing, and quietly
     * registering people into the wrong gym is worse than saying no.
     */
    private function branch(string $code): Branch
    {
        return Branch::query()->where('code', $code)->firstOrFail();
    }

    /** GET /join/{code} */
    public function show(string $code): View
    {
        $branch = $this->branch($code);

        return view('site.join-form', [
            'gymName' => $this->gymName(),
            'code' => $code,
            'branch' => $branch,
            'questions' => ParQ::questions(),
            'extras' => RegistrationQuestion::forBranch($branch->getKey())->get(),
            'terms' => $this->screenings->terms($branch->getKey()),
        ]);
    }

    /** POST /join/{code} */
    public function submit(Request $request, string $code): RedirectResponse
    {
        $branch = $this->branch($code);

        $rules = [
            // Only the name is genuinely required. A form that refuses to
            // send because somebody has no e-mail address is a form they
            // give up on and hand back to reception to type in instead.
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'nic_passport' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:MALE,FEMALE,OTHER'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_number' => ['nullable', 'string', 'max:30'],

            'signed_name' => ['required', 'string', 'min:2', 'max:255'],
            'signature' => ['required', 'string', 'max:2000000'],
            'agree' => ['accepted'],
        ];

        foreach (ParQ::ids() as $id) {
            // Every question must be answered. A blank is not a no: somebody
            // who skipped question 3 has not told us they have no chest pain.
            $rules["answers.{$id}.answer"] = ['required', 'in:yes,no'];
            $rules["answers.{$id}.detail"] = ['nullable', 'string', 'max:500'];
        }

        $extras = RegistrationQuestion::forBranch($branch->getKey())->get();
        $rules += RegistrationForm::rules($extras);

        $data = $request->validate($rules, [
            'agree.accepted' => 'You need to agree to the terms before signing.',
            'signature.required' => 'Please sign in the box.',
            'answers.*.answer.required' => 'Please answer every question.',
            'full_name.required' => 'Please tell us your name.',
        ]);

        /*
         * The honeypot. Answered with success rather than an error, because
         * telling a bot which check it failed is telling it how to pass next
         * time - and a real person can never see this field to fill it in.
         */
        if (filled($request->input('website'))) {
            Log::info('Dropped a self-registration that filled the honeypot.', [
                'branch' => $branch->code,
                'ip' => $request->ip(),
            ]);

            return redirect()->route('site.join.done', ['code' => $code])
                ->with('first_name', 'there');
        }

        $answers = [];

        foreach (ParQ::ids() as $id) {
            $yes = ($data['answers'][$id]['answer'] ?? 'no') === 'yes';

            $answers[$id] = [
                'answer' => $yes,
                // Detail on a "no" is noise at best and a contradiction at
                // worst, so it is dropped rather than stored.
                'detail' => $yes ? ($data['answers'][$id]['detail'] ?? null) : null,
            ];
        }

        $details = collect($data)
            ->only([
                'full_name', 'nic_passport', 'date_of_birth', 'gender', 'address', 'city',
                'telephone', 'email', 'emergency_contact_name', 'emergency_contact_number',
            ])
            ->all();

        try {
            $registration = $this->registrations->submit(
                $branch->getKey(),
                $details,
                $answers,
                RegistrationForm::answers($extras, $data['custom'] ?? []),
                $data['signature'],
                trim($data['signed_name']),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['signature' => $e->getMessage()]);
        }

        return redirect()->route('site.join.done', ['code' => $code])
            ->with('first_name', str($registration->full_name)->trim()->before(' ')->value());
    }

    /**
     * GET /join/{code}/done
     *
     * Carries no identifier. There is nothing here worth looking up, and a
     * reference in the URL would be a registration anyone could enumerate.
     */
    public function done(string $code): View
    {
        $this->branch($code);

        return view('site.join-done', [
            'gymName' => $this->gymName(),
            'firstName' => session('first_name', 'there'),
        ]);
    }
}
