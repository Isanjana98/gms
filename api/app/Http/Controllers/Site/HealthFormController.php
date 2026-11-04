<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\HealthScreening;
use App\Services\HealthScreeningService;
use App\Services\ScreeningRenderer;
use App\Support\GymSettings;
use App\Support\ParQ;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The form the member fills in on their own phone.
 *
 * This is the only unauthenticated write endpoint in the system that stores
 * health data, so the rules it works to are tighter than anywhere else:
 *
 *  - **The token is the whole authorisation.** It is long, random, hashed at
 *    rest, single-use and expiring. There is no other credential, because the
 *    member has not got one yet.
 *  - **It identifies, it does not disclose.** The form shows the member's name
 *    so they know the right form is in front of them, and nothing else. Not
 *    their phone number, not their plan, not what they owe — a QR photographed
 *    over somebody's shoulder must be worth as little as possible.
 *  - **A bad or spent token is a flat 404.** Never "this link has expired for
 *    Nimal Perera", which would turn a guessed token into a membership oracle.
 *  - **Submitting is idempotent in effect.** A second POST with a spent token
 *    finds nothing and 404s, so a double-tap on a slow connection cannot
 *    produce two screenings.
 */
class HealthFormController extends Controller
{
    public function __construct(
        private readonly HealthScreeningService $screenings,
        private readonly ScreeningRenderer $renderer,
    ) {}

    private function gymName(): string
    {
        return (string) app(GymSettings::class)
            ->get('branding.gym_name', null, config('app.name'));
    }

    /** GET /health/{token} */
    public function show(string $token): View
    {
        $screening = $this->screenings->findByToken($token);

        abort_if($screening === null, 404);

        return view('site.health-form', [
            'gymName' => $this->gymName(),
            'token' => $token,
            'screening' => $screening,
            'memberName' => $screening->member->full_name,
            'questions' => ParQ::questions(),
            'terms' => $this->screenings->terms($screening->branch_id),
        ]);
    }

    /** POST /health/{token} */
    public function submit(Request $request, string $token): RedirectResponse
    {
        $screening = $this->screenings->findByToken($token);

        abort_if($screening === null, 404);

        $rules = [
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

        $data = $request->validate($rules, [
            'agree.accepted' => 'You need to agree to the terms before signing.',
            'signature.required' => 'Please sign in the box.',
            'answers.*.answer.required' => 'Please answer every question.',
        ]);

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

        try {
            $screening = $this->screenings->complete(
                $screening,
                $answers,
                $data['signature'],
                trim($data['signed_name']),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['signature' => $e->getMessage()]);
        }

        // File the PDF, but never at the cost of the submission. The member is
        // standing at the counter and the signed record is already safe in the
        // database; a dompdf failure must not throw their answers away and
        // make them do it again.
        try {
            $this->screenings->fileDocument($screening, $this->renderer->bytes($screening));
        } catch (\Throwable $e) {
            Log::error('Could not file the health screening PDF.', [
                'screening' => $screening->reference,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()->route('site.health.done', ['reference' => $screening->reference]);
    }

    /**
     * GET /health/done/{reference}
     *
     * Deliberately takes the reference rather than the token: the token is
     * spent by now, and this page has to survive a refresh.
     */
    public function done(string $reference): View
    {
        $screening = HealthScreening::query()
            ->completed()
            ->where('reference', $reference)
            // Within a few minutes of signing. The reference is not a secret
            // and is printed on the PDF, so this page must not stay a working
            // lookup for anybody who has ever seen one.
            ->where('signed_at', '>=', now()->subMinutes(30))
            ->first();

        abort_if($screening === null, 404);

        return view('site.health-done', [
            'gymName' => $this->gymName(),
            'reference' => $screening->reference,
            'hasFlags' => $screening->has_flags,
        ]);
    }
}
