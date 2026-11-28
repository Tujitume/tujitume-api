<?php

namespace App\Http\Controllers\Capital;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\CapitalProfile;
use App\Models\Capital\CapitalTermsAgreement;
use App\Models\Capital\CapitalWatchlist;
use App\Models\Capital\StartupPitches;
use App\Models\Communication\Messages;
use App\Models\Finance\BalanceLog;
use App\Models\Services\ServiceBooking;
use App\Models\Shared\Watchlist;
use App\Service\Account\AccountDeletionEligibilityService;
use App\Service\Balance\BalanceService;
use App\Service\Capital\CapitalAccess;
use App\Service\Capital\CapitalFiles;
use App\Service\Capital\CapitalMatchScore;
use App\Service\Capital\CapitalNotifier;
use App\Service\Capital\CapitalRelease;
use App\Service\File\ImageCompressor;
use App\Service\Misc\ErrorLogService;
use App\Service\Organization\OrganizationPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

/**
 * Capital offers, applications (pitches), terms agreements and the capital team. A capital provider is user type 5
 * (CapitalAccess); entrepreneurs (type 1) discover offers, apply and negotiate terms. Who may call which route is decided
 * first by CapitalMiddleware; every method that touches an offer or an application checks it belongs to the caller.
 */
class CapitalController extends Controller
{
    protected $Client;

    public function __construct(StripeClient $client)
    {
        parent::__construct();
        $this->Client = $client;
        $this->balance = new BalanceService();
    }

    private function forbidden(string $message)
    {
        return response()->json(['success' => false, 'code' => 'forbidden', 'message' => $message, 'error' => $message], 403);
    }

    private function failed(\Throwable $e)
    {
        ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);

        return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
    }

    /** "a,b" / '["a","b"]' / ["a","b"] → ["a","b"] (the edit form used to send the lists as text) */
    private static function asList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $value), fn ($v) => $v !== ''));
        }
        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);

        return is_array($decoded) ? self::asList($decoded) : self::asList(explode(',', $text));
    }

    // ─── Offers ──────────────────────────────────────────────────────────

    /**
     * GET capital/capital-offers (and the public /home/capital-offers)
     *  - a capital provider (owner or team): their own offers, hidden ones too. With list parameters (page, search,
     *    filters, sort, export) the server list {capital, data}; without, {capital: [...]}.
     *  - anyone else (entrepreneurs, service providers, ...): with list parameters the Explore Capital server list
     *    (visible offers, with timing open | closingSoon | upcoming | closed); without, {capital: [...]} of the visible
     *    offers open today, with the person's own application on each.
     */
    public function index()
    {
        $user = Auth::user() ?? Auth::guard('sanctum')->user();

        if (CapitalAccess::isCapital($user)) {
            $ownerId = CapitalAccess::ownerId($user);
            if (\App\Support\ListShapes::wanted(request())) {
                return (new \App\Service\Capital\CapitalDiscoverList($user, $ownerId))->respond(request());
            }
            $capitals = CapitalOffer::with('owner:id,website')->withCount('liked')->where('user_id', $ownerId)->latest()->get();
            $watchlist = Watchlist::where('user_id', $ownerId)->where('org_type', 'capital')->pluck('org_id')->map(fn ($id) => (int) $id)->all();
            $counts = StartupPitches::whereIn('capital_id', $capitals->pluck('id'))->notRejected()
                ->selectRaw('capital_id, count(*) as n')->groupBy('capital_id')->pluck('n', 'capital_id');
            foreach ($capitals as $capital) {
                $capital->in_my_watchlist = in_array((int) $capital->id, $watchlist, true);
                $capital->liked = $capital->liked()->where('user_id', $user->id)->exists();
                $capital->pitch_count = (int) ($counts[$capital->id] ?? 0);
                $capital->owner_website = $capital->owner?->website;
                $capital->unsetRelation('owner');
            }

            return response()->json(['capital' => $capitals]);
        }

        if ($user && \App\Support\ListShapes::wanted(request())) {
            return (new \App\Service\Capital\CapitalDiscoverList($user))->respond(request());
        }

        $capitals = CapitalOffer::with('owner:id,website')->withCount('liked')->openToPublic()->latest()->get();
        $counts = StartupPitches::whereIn('capital_id', $capitals->pluck('id'))->notRejected()
            ->selectRaw('capital_id, count(*) as n')->groupBy('capital_id')->pluck('n', 'capital_id');
        foreach ($capitals as $capital) {
            $capital->pitch_count = (int) ($counts[$capital->id] ?? 0);
            $capital->owner_website = $capital->owner?->website;
            $capital->unsetRelation('owner');
        }

        // Signed-in entrepreneur: their own application on each offer (one query)
        if ($user) {
            $this->attachMyApplications($capitals, $user);
        }

        return response()->json(['capital' => $capitals]);
    }

    /**
     * Adds applied, application_status (pending | approved | rejected), applied_at, application_id and
     * amount_requested to each offer for the given user (or their organization owner), from their newest application.
     */
    private function attachMyApplications($capitals, $user): void
    {
        $ownerIds = array_unique([$user->id, $user->organizationOwnerId()]);
        $mine = StartupPitches::whereIn('capital_id', collect($capitals)->pluck('id'))
            ->whereIn('user_id', $ownerIds)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'capital_id', 'status', 'created_at', 'total_amount_requested'])
            ->unique('capital_id')
            ->keyBy('capital_id');

        foreach ($capitals as $capital) {
            $pitch = $mine->get($capital->id);
            $capital->applied = (bool) $pitch;
            $capital->application_id = $pitch?->id;
            $capital->application_status = $pitch ? StartupPitches::statusName($pitch->status) : null;
            $capital->applied_at = $pitch?->created_at;
            $capital->amount_requested = $pitch?->total_amount_requested;
        }
    }

    /** GET capital/get_capital/{id}: one offer. Hidden offers only for their owner; unknown → 404. */
    public function get_capital($id)
    {
        $capital = CapitalOffer::with('owner:id,first_name,last_name,display_name,website,image')->withCount('liked')->find($id);
        $user = Auth::guard('sanctum')->user() ?? Auth::user();
        $mine = CapitalAccess::ownsOffer($user, $capital);
        if (! $capital || (! $mine && (int) $capital->visible !== 1)) {
            return response()->json(['message' => 'Capital offer not found.'], 404);
        }

        $capital->pitch_count = StartupPitches::where('capital_id', $capital->id)->notRejected()->count();
        $capital->owner_website = $capital->owner?->website;
        $capital->is_open = CapitalOffer::whereKey($capital->id)->openToPublic()->exists();
        $capital->is_mine = $mine;
        if ($user) {
            $capital->liked = $capital->liked()->where('user_id', $user->id)->exists();
            if (! $mine) {
                $this->attachMyApplications([$capital], $user);
            }
        }

        return response()->json(['capital-data' => $capital], 200);
    }

    /** GET capital/visibility/{id}: show / hide one of my offers */
    public function visibility($capital_id)
    {
        $capital = CapitalOffer::find($capital_id);
        if (! $capital) {
            return response()->json(['message' => 'Capital offer not found.'], 404);
        }
        if (! CapitalAccess::ownsOffer(Auth::user(), $capital)) {
            return $this->forbidden('Only the capital provider who owns this offer can change it.');
        }
        $capital->update(['visible' => (int) $capital->visible === 1 ? 0 : 1]);

        return response()->json(['message' => 'Visibility Changed.', 'visible' => (int) $capital->visible], 200);
    }

    /** POST capital/create-capital-offer */
    public function store(Request $request)
    {
        $data = $request->validate([
            'offer_title' => 'required|string|max:255',
            'total_capital_available' => 'required|numeric|min:0',
            'per_startup_allocation' => 'required|numeric|min:0',
            'milestone_requirements' => 'nullable|string',
            'startup_stage' => 'required',
            'sectors' => 'required',
            'regions' => 'required',
            'impact_objectives' => 'nullable|string|max:700',
            'required_docs' => 'nullable|string|max:200',
            'offer_brief_file' => 'nullable|file|mimes:pdf|max:5120',
            'end_date' => 'nullable|date',
            'start_date' => 'nullable|date',
        ]);
        foreach (['startup_stage', 'sectors', 'regions'] as $field) {
            $data[$field] = self::asList($data[$field]);
            if (! $data[$field]) {
                throw ValidationException::withMessages([$field => ['Choose at least one.']]);
            }
        }
        if (! empty($data['start_date']) && ! empty($data['end_date']) && $data['end_date'] < $data['start_date']) {
            throw ValidationException::withMessages(['end_date' => ['The end date must be after the start date.']]);
        }

        $path = null;
        try {
            $capital = DB::transaction(function () use ($data, $request, &$path) {
                $capital = CapitalOffer::create([
                    'user_id' => CapitalAccess::ownerId(Auth::user()),
                    'offer_title' => $data['offer_title'],
                    'total_capital_available' => $data['total_capital_available'],
                    'available_amount' => $data['total_capital_available'],
                    'per_startup_allocation' => $data['per_startup_allocation'],
                    'milestone_requirements' => $data['milestone_requirements'] ?? null,
                    'startup_stage' => $data['startup_stage'],
                    'sectors' => $data['sectors'],
                    'regions' => $data['regions'],
                    'impact_objectives' => $data['impact_objectives'] ?? null,
                    'required_docs' => $data['required_docs'] ?? null,
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                ]);
                $path = CapitalFiles::store($request->file('offer_brief_file'), 'files/capitals/'.$capital->id);
                $capital->update(['offer_brief_file' => $path ?? '']);

                return $capital;
            });

            return response()->json(['message' => 'List Capital created successfully', 'capital' => $capital->fresh()], 200);
        } catch (\Throwable $e) {
            CapitalFiles::delete($path);

            return $this->failed($e);
        }
    }

    /** POST capital/update-capital {id, ...the store fields} */
    public function update(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $capital = CapitalOffer::find($request->id);
        if (! $capital) {
            return response()->json(['message' => 'Capital offer not found.'], 404);
        }
        if (! CapitalAccess::ownsOffer(Auth::user(), $capital)) {
            return $this->forbidden('Only the capital provider who owns this offer can change it.');
        }

        $data = $request->validate([
            'offer_title' => 'required|string|max:255',
            'per_startup_allocation' => 'required|numeric|min:0',
            'total_capital_available' => 'nullable|numeric|min:0',
            'milestone_requirements' => 'nullable|string',
            'startup_stage' => 'required',
            'sectors' => 'required',
            'regions' => 'required',
            'impact_objectives' => 'nullable|string|max:700',
            'required_docs' => 'nullable|string|max:200',
            'offer_brief_file' => 'nullable|file|mimes:pdf|max:5120',
            'end_date' => 'nullable|date',
            'start_date' => 'nullable|date',
        ]);
        foreach (['startup_stage', 'sectors', 'regions'] as $field) {
            $data[$field] = self::asList($data[$field]);
        }
        unset($data['offer_brief_file']);

        // Changing the pool keeps what was already committed taken off what is available
        if (isset($data['total_capital_available'])) {
            $committed = (float) $capital->total_capital_available - (float) $capital->available_amount;
            if ((float) $data['total_capital_available'] < $committed) {
                throw ValidationException::withMessages(['total_capital_available' => ['The total cannot be less than what is already released ('.$committed.').']]);
            }
            $data['available_amount'] = (float) $data['total_capital_available'] - $committed;
        } else {
            unset($data['total_capital_available']);
        }

        try {
            if ($file = $request->file('offer_brief_file')) {
                $old = $capital->offer_brief_file;
                $data['offer_brief_file'] = CapitalFiles::store($file, 'files/capitals/'.$capital->id);
                CapitalFiles::delete($old);
            }
            $capital->update($data);

            return response()->json(['message' => 'Capital updated successfully', 'capital' => $capital->fresh()], 200);
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /** GET capital/delete-capital/{id}: not when an application was accepted (money and terms hang on it) */
    public function destroy($id)
    {
        $capital = CapitalOffer::find($id);
        if (! $capital) {
            return response()->json(['message' => 'Capital offer not found.'], 404);
        }
        if (! CapitalAccess::ownsOffer(Auth::user(), $capital)) {
            return $this->forbidden('Only the capital provider who owns this offer can delete it.');
        }
        if (StartupPitches::where('capital_id', $capital->id)->where('status', StartupPitches::STATUS_ACCEPTED)->exists()) {
            return response()->json(['message' => 'This offer has accepted applications: hide it instead of deleting it.'], 422);
        }
        CapitalFiles::delete($capital->offer_brief_file);
        $capital->delete();

        return response()->json(['message' => 'Capital Offer deleted successfully']);
    }

    // ─── Applications (pitches) ──────────────────────────────────────────

    /**
     * GET capital/pitches/{capital_id}: the applications to one of my offers; pitches/latest: the entrepreneur's own.
     */
    public function pitches($capital_id)
    {
        $user = Auth::user();
        if ($capital_id === 'latest') {
            $pitches = StartupPitches::with(['capital_milestones', 'capital_offer:id,offer_title,user_id'])
                ->whereIn('user_id', array_unique([$user->id, $user->organizationOwnerId()]))->latest()->get();

            return response()->json(['pitches' => $pitches]);
        }

        $capital = CapitalOffer::find($capital_id);
        if (! $capital) {
            return response()->json(['message' => 'Capital offer not found.'], 404);
        }
        if (! CapitalAccess::ownsOffer($user, $capital)) {
            return $this->forbidden('Only the capital provider who owns this offer can see its applications.');
        }
        $watch = CapitalWatchlist::where('capital_owner_id', $capital->user_id)->pluck('pitch_id')->map(fn ($id) => (int) $id)->all();
        $pitches = StartupPitches::with(['capital_milestones', 'latestAgreement'])->where('capital_id', $capital->id)->latest()->get()
            ->each(fn ($p) => $p->in_my_watchlist = in_array((int) $p->id, $watch, true));

        return response()->json(['pitches' => $pitches]);
    }

    /** GET capital/pitch/{id}: one application, for its capital provider (and team) or the entrepreneur who made it */
    public function pitch_details($id)
    {
        $user = Auth::user();
        $pitch = StartupPitches::with(['capital_milestones', 'capital_offer', 'latestAgreement', 'sme:id,first_name,last_name,display_name,email,image'])->find($id);
        if (! $pitch) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        $provider = CapitalAccess::isProviderOf($user, $pitch);
        if (! $provider && ! CapitalAccess::isApplicantOf($user, $pitch)) {
            return $this->forbidden('This application is not yours.');
        }
        $pitch->application_status = StartupPitches::statusName($pitch->status);
        $pitch->viewer_side = $provider ? 'provider' : 'applicant';
        $pitch->released_amount = round((float) $pitch->capital_milestones->where('fund_released', true)->sum('amount'), 2);
        $pitch->next_milestone = CapitalRelease::unreleased($pitch->id)->first();
        // DECISION-9: each milestone's project manager audit (provider: with the fee and what they can do)
        \App\Service\Audits\CapitalAudits::attach($pitch, $provider, $user);
        if ($provider) {
            $pitch->in_my_watchlist = CapitalWatchlist::where('capital_owner_id', $pitch->capital_owner_id)->where('pitch_id', $pitch->id)->exists();
        }

        return response()->json(['pitch' => $pitch]);
    }

    /**
     * GET capital/my_pitches: a capital provider's applications (all their offers), or an entrepreneur's own, with the
     * header numbers.
     */
    public function mypitches()
    {
        $user = Auth::user();
        // the Applications inbox asks for a page / search / filters / sort / export: the server list
        if (\App\Support\ListShapes::wanted(request())) {
            return (new \App\Service\Capital\CapitalPitchList($user))->respond(request());
        }
        $with = ['capital_offer', 'capital_milestones', 'latestAgreement'];
        if (CapitalAccess::isCapital($user)) {
            $ownerId = CapitalAccess::ownerId($user);
            $watch = CapitalWatchlist::where('capital_owner_id', $ownerId)->pluck('pitch_id')->map(fn ($id) => (int) $id)->all();
            $pitches = StartupPitches::with($with)->where('capital_owner_id', $ownerId)->latest()->get();
        } else {
            $watch = [];
            $pitches = StartupPitches::with($with)->whereIn('user_id', array_unique([$user->id, $user->organizationOwnerId()]))->latest()->get();
        }

        foreach ($pitches as $pitch) {
            $pitch->in_my_watchlist = in_array((int) $pitch->id, $watch, true);
            $pitch->application_status = StartupPitches::statusName($pitch->status);
        }

        $count = $pitches->count();
        $accepted = $pitches->where('status', StartupPitches::STATUS_ACCEPTED)->count();

        return response()->json([
            'pitches' => $pitches,
            'avg_match_score' => round($count > 0 ? $pitches->sum('score') / $count : 0, 2),
            'accept_rate' => $count > 0 ? round($accepted / $count * 100, 2) : 0,
            'pitch_count' => $count,
            'pitch_count_pending' => $pitches->where('status', StartupPitches::STATUS_PENDING)->count(),
            'pitch_count_accepted' => $accepted,
            'pitch_count_rejected' => $pitches->where('status', StartupPitches::STATUS_REJECTED)->count(),
        ]);
    }

    /**
     * POST capital/investment-application (multipart): an entrepreneur applies to an open offer. The match score is
     * worked out here (CapitalMatchScore), not taken from the request. Milestones are optional:
     * milestones[i][title], [amount], [description], [deliverables][file].
     */
    public function store_application(Request $request)
    {
        $user = Auth::user();
        $ownerIds = array_unique([$user->id, $user->organizationOwnerId()]);
        $hasBusiness = Listing::whereIn('user_id', $ownerIds)->exists();

        $data = $request->validate([
            'capital_id' => 'required|integer|exists:capital_offers,id',
            'business_id' => [Rule::requiredIf($hasBusiness), 'nullable', 'integer', Rule::exists('listings', 'id')->whereIn('user_id', $ownerIds)],
            'startup_name' => 'required|string|max:255',
            'contact_person_name' => 'required|string|max:100',
            'contact_person_email' => 'required|email|max:100',
            'sector' => 'required|string|max:255',
            'headquarters_location' => 'required|string|max:255',
            'stage' => 'required|string|max:100',
            'revenue_last_12_months' => 'nullable|numeric|min:0',
            'team_experience_avg_years' => 'nullable|integer|min:0',
            'traction_kpis' => 'nullable|string',
            'pitch_deck_file' => 'nullable|file|mimes:pdf,docx,pptx|max:10240',
            'pitch_video_file' => 'nullable|file|mimes:mp4,avi,mkv,mov|max:51200',
            'business_plan' => 'nullable|file|mimes:pdf,docx|max:10240',
            'social_impact_areas' => 'nullable|array',
            'social_impact_areas.*' => 'string|max:255',
            'cac_ltv' => 'nullable|numeric',
            'burn_rate' => 'nullable|numeric|min:0',
            'irr_projection' => 'nullable|numeric',
            'exit_strategy' => 'nullable|string',
            'total_amount_requested' => 'required|numeric|min:1',
            'bonus_points' => 'nullable',
            'milestones' => 'nullable|array',
            'milestones.*.title' => 'required_with:milestones|string|max:500',
            'milestones.*.amount' => 'required_with:milestones|numeric|min:0',
            'milestones.*.description' => 'nullable|string|max:1000',
            'milestones.*.deliverables.file' => 'nullable|file|mimes:pdf,doc,docx,png,jpg,jpeg|max:10240',
        ], [
            'business_id.required' => 'Choose the business you are applying with.',
            'business_id.exists' => 'Choose one of your own businesses.',
        ]);

        $offer = CapitalOffer::find($data['capital_id']);
        if (! CapitalOffer::whereKey($offer->id)->openToPublic()->exists()) {
            return response()->json(['message' => 'This capital offer is not open for applications.'], 422);
        }
        if (CapitalAccess::ownsOffer($user, $offer)) {
            return response()->json(['message' => 'You cannot apply to your own capital offer.'], 422);
        }
        if (StartupPitches::where('capital_id', $offer->id)->whereIn('user_id', $ownerIds)->where('status', '!=', StartupPitches::STATUS_REJECTED)->exists()) {
            return response()->json(['message' => 'You have already applied to this capital offer.'], 422);
        }
        if ((float) $data['total_amount_requested'] > (float) $offer->per_startup_allocation && (float) $offer->per_startup_allocation > 0) {
            throw ValidationException::withMessages(['total_amount_requested' => ['The most this offer gives one startup is '.$offer->per_startup_allocation.'.']]);
        }
        $milestones = $data['milestones'] ?? [];
        if ($milestones && round(array_sum(array_map(fn ($m) => (float) $m['amount'], $milestones)), 2) > round((float) $data['total_amount_requested'], 2)) {
            throw ValidationException::withMessages(['milestones' => ['The milestones add up to more than the amount requested.']]);
        }

        $match = (new CapitalMatchScore())->score($offer, $request);
        $stored = [];

        try {
            $pitch = DB::transaction(function () use ($data, $user, $offer, $match, $milestones, $request, &$stored) {
                $pitch = StartupPitches::create([
                    'user_id' => $user->id,
                    'capital_id' => $offer->id,
                    'business_id' => $data['business_id'] ?? null,
                    'capital_owner_id' => $offer->user_id,
                    'startup_name' => $data['startup_name'],
                    'contact_person_name' => $data['contact_person_name'],
                    'contact_person_email' => $data['contact_person_email'],
                    'sector' => $data['sector'],
                    'headquarters_location' => $data['headquarters_location'],
                    'stage' => $data['stage'],
                    'revenue_last_12_months' => $data['revenue_last_12_months'] ?? 0,
                    'team_experience_avg_years' => $data['team_experience_avg_years'] ?? null,
                    'traction_kpis' => $data['traction_kpis'] ?? null,
                    'social_impact_areas' => $data['social_impact_areas'] ?? null,
                    'cac_ltv' => $data['cac_ltv'] ?? null,
                    'burn_rate' => $data['burn_rate'] ?? 0,
                    'irr_projection' => $data['irr_projection'] ?? null,
                    'exit_strategy' => $data['exit_strategy'] ?? null,
                    'score' => (int) round($match['score']),
                    'score_breakdown' => json_encode($match['score_breakdown']),
                    'total_amount_requested' => $data['total_amount_requested'],
                    'status' => StartupPitches::STATUS_PENDING,
                ]);

                $folder = 'files/capitalPitches/'.$pitch->id;
                $files = [
                    'pitch_deck_file' => CapitalFiles::store($request->file('pitch_deck_file'), $folder),
                    'pitch_video' => CapitalFiles::store($request->file('pitch_video_file'), $folder),
                    'business_plan' => CapitalFiles::store($request->file('business_plan'), $folder),
                ];
                $stored = array_filter($files);
                $pitch->update(array_map(fn ($p) => $p ?? '', $files));

                foreach (array_values($milestones) as $i => $milestone) {
                    $document = CapitalFiles::store($request->file("milestones.$i.deliverables.file"), 'files/capitalMiles/'.$pitch->id);
                    if ($document) {
                        $stored[] = $document;
                    }
                    CapitalMilestone::create([
                        'app_id' => $pitch->id,
                        'title' => $milestone['title'],
                        'amount' => $milestone['amount'],
                        'description' => $milestone['description'] ?? null,
                        'document' => $document ?? '',
                    ]);
                }

                return $pitch;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                CapitalFiles::delete($path);
            }

            return $this->failed($e);
        }

        CapitalNotifier::send(
            CapitalAccess::providerRecipients((int) $offer->user_id), (int) $user->id,
            'New application from '.$pitch->startup_name.' for '.$offer->offer_title.'.',
            CapitalNotifier::providerPitch($pitch->id),
            'opportunities.capital_pitch', ['capital' => $offer->offer_title, 'SME' => $pitch->startup_name], 'New capital application: '.$pitch->startup_name
        );

        return response()->json([
            'message' => 'Investment Application Successfull.',
            'pitch' => $pitch->fresh(['capital_milestones']),
            'score' => $match['score'],
            'result' => $match['result'],
            'score_breakdown' => $match['score_breakdown'],
        ], 200);
    }

    /** The application, only when it was made to the signed-in capital provider (or their team). Else 404. */
    private function ownPitchOrFail($pitch_id): StartupPitches
    {
        $pitch = StartupPitches::with('capital_offer')->find($pitch_id);
        abort_unless($pitch && $pitch->capital_offer && CapitalAccess::isProviderOf(Auth::user(), $pitch), 404, 'Application not found.');

        return $pitch;
    }

    /** GET capital/accept/{pitch_id} */
    public function accept($pitch_id)
    {
        $pitch = $this->ownPitchOrFail($pitch_id);
        if ((int) $pitch->status === StartupPitches::STATUS_ACCEPTED) {
            return response()->json(['message' => 'Pitch Accepted.'], 200);
        }
        try {
            DB::transaction(function () use ($pitch) {
                $pitch->update(['status' => StartupPitches::STATUS_ACCEPTED, 'rejection_reason' => null]);
                // No milestones were planned: one tranche for the whole amount, so funds can be released
                if (! CapitalMilestone::where('app_id', $pitch->id)->exists()) {
                    CapitalMilestone::create(['app_id' => $pitch->id, 'title' => 'Full amount', 'amount' => (int) round((float) $pitch->total_amount_requested), 'description' => null, 'document' => '']);
                }
            });
        } catch (\Throwable $e) {
            return $this->failed($e);
        }

        $acceptedText = 'Your application to '.$pitch->capital_offer->offer_title.' has been accepted. You can now agree the terms with the capital provider.';
        CapitalNotifier::send([$pitch->user_id], (int) $pitch->capital_owner_id, $acceptedText, CapitalNotifier::applicantDeal($pitch->id),
            'capital.notice', ['title' => 'Application accepted', 'body' => $acceptedText, 'button' => 'Open the deal room'],
            'Your capital application was accepted: '.$pitch->capital_offer->offer_title);

        return response()->json(['message' => 'Pitch Accepted.'], 200);
    }

    /** GET|POST capital/reject/{pitch_id} {reason?}: kept with status 2 (rejected), so the entrepreneur sees it */
    public function reject(Request $request, $pitch_id)
    {
        $pitch = $this->ownPitchOrFail($pitch_id);
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        if (CapitalMilestone::where('app_id', $pitch->id)->where('fund_released', true)->exists()) {
            return response()->json(['message' => 'Funds were already released for this application.'], 422);
        }
        $pitch->update(['status' => StartupPitches::STATUS_REJECTED, 'rejection_reason' => $data['reason'] ?? null]);

        $text = 'Your application to '.$pitch->capital_offer->offer_title.' was not selected this time.'
            .(! empty($data['reason']) ? ' Reason: '.$data['reason'] : '');
        CapitalNotifier::send([$pitch->user_id], (int) $pitch->capital_owner_id, $text, CapitalNotifier::applicantApplication($pitch->id),
            'capital.notice', ['title' => 'An update on your application', 'body' => $text, 'button' => 'View my application'],
            'An update on your capital application: '.$pitch->capital_offer->offer_title);

        return response()->json(['message' => 'Pitch Rejected.'], 200);
    }

    /**
     * GET capital/fund-release-request/{pitch_id}: the entrepreneur asks the capital provider to release the next
     * tranche (the grant flow of the old app had the same step). The provider and their team are told.
     */
    public function fund_request($pitch_id)
    {
        $user = Auth::user();
        $pitch = StartupPitches::with(['capital_offer', 'latestAgreement'])->find($pitch_id);
        if (! $pitch || ! $pitch->capital_offer) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        if (! CapitalAccess::isApplicantOf($user, $pitch)) {
            return $this->forbidden('Only the entrepreneur who applied can ask for funds.');
        }
        if ((int) $pitch->status !== StartupPitches::STATUS_ACCEPTED || ($pitch->latestAgreement?->status) !== 'accepted') {
            return response()->json(['message' => 'Funds can be requested once the application and its terms are accepted.'], 422);
        }
        $next = CapitalRelease::unreleased($pitch->id)->first();
        if (! $next) {
            return response()->json(['message' => 'Every milestone has already been released.'], 422);
        }
        if ($pitch->fund_requested_at && $pitch->fund_requested_at->gt(now()->subDay())) {
            return response()->json(['message' => 'You already asked for this release today.'], 429);
        }
        $pitch->update(['fund_requested_at' => now()]);

        $currency = config('app.currency', env('CURRENCY', 'KES'));
        $askText = $pitch->startup_name.' asked you to release "'.$next->title.'" ('.$currency.' '.number_format((float) $next->amount).') for '.$pitch->capital_offer->offer_title.'.';
        CapitalNotifier::send(CapitalAccess::providerRecipients((int) $pitch->capital_owner_id), (int) $user->id, $askText,
            CapitalNotifier::providerDeal($pitch->id, 'funding'),
            'capital.notice', ['title' => 'Release requested', 'body' => $askText, 'button' => 'Review the release'],
            'Release requested: '.$next->title.' for '.$pitch->startup_name);

        return response()->json(['message' => 'Fund Requested.', 'milestone' => $next], 200);
    }

    // ─── Watchlist (kept on the capital owner, shared by the team) ───────

    /** GET capital/store-watchlist/{pitch_id}: add / remove one of my applications */
    public function store_watchlist($pitch_id)
    {
        $pitch = $this->ownPitchOrFail($pitch_id);
        $ownerId = (int) $pitch->capital_owner_id;
        $deleted = CapitalWatchlist::where('pitch_id', $pitch->id)->where('capital_owner_id', $ownerId)->delete();
        if ($deleted) {
            return response()->json(['message' => 'Removed from watchlist', 'in_my_watchlist' => false], 200);
        }
        CapitalWatchlist::create(['pitch_id' => $pitch->id, 'capital_owner_id' => $ownerId]);

        return response()->json(['message' => 'Added to watchlist', 'in_my_watchlist' => true], 200);
    }

    /** GET capital/get-watchlist */
    public function get_watchlist()
    {
        $ownerId = CapitalAccess::ownerId(Auth::user());
        $pitches = CapitalWatchlist::with('pitch.capital_offer:id,offer_title')->where('capital_owner_id', $ownerId)->latest()->get()
            ->pluck('pitch')->filter(fn ($p) => $p && (int) $p->capital_owner_id === $ownerId)->values();

        return response()->json(['pitches' => $pitches], 200);
    }

    // ─── Profile and team ────────────────────────────────────────────────

    /** POST capital/update-profile (owner / super admin): the same fields as sign-up */
    public function update_profile(Request $request, ImageCompressor $obj)
    {
        $user = Auth::user();
        $ownerId = CapitalAccess::ownerId($user);
        $owner = User::find($ownerId);
        $data = $request->validate([
            'org_name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'org_type' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'inv_range' => 'nullable|array',
            'sectors' => 'nullable|array',
            'interested_cats' => 'nullable|array', // older name of sectors
            'startup_stage' => 'nullable|array',
            'regions' => 'nullable|array',
            'eng_prefer' => 'nullable|array',
            'turnover_range' => 'nullable|array',
            'social_impact_areas' => 'nullable|array',
            'investment_guidelines' => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            'image' => 'nullable|image|max:2048',
        ]);

        try {
            $owner->update(array_filter([
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'display_name' => $data['org_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'website' => $data['website'] ?? null,
            ], fn ($v) => $v !== null));

            $profile = $owner->capital_profile()->firstOrCreate(['user_id' => $owner->id], ['active' => 1]);
            $fields = ['org_type' => $data['org_type']];
            foreach (['org_name', 'inv_range', 'startup_stage', 'regions', 'eng_prefer', 'turnover_range', 'social_impact_areas', 'sectors'] as $key) {
                if (array_key_exists($key, $data)) {
                    $fields[$key] = $data[$key];
                }
            }
            if (! isset($fields['sectors']) && isset($data['interested_cats'])) {
                $fields['sectors'] = $data['interested_cats'];
            }
            if ($file = $request->file('investment_guidelines')) {
                $old = $profile->investment_guidelines;
                $fields['investment_guidelines'] = CapitalFiles::store($file, 'files/capitalProfiles/'.$owner->id);
                CapitalFiles::delete($old);
            }
            $profile->update($fields);

            if ($image = $request->file('image')) {
                $loc = 'images/users/';
                $name = hexdec(uniqid()).'.'.strtolower($image->getClientOriginalExtension());
                $obj->compressImage($image, $loc.$name, 60);
                $owner->update(['image' => $this->api_base_url.$loc.$name]);
            }

            return response()->json(['message' => 'Profile updated successfully', 'capital_profile' => $profile->fresh()], 200);
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /** GET capital/team: the capital organization's team members */
    public function team()
    {
        $ownerId = CapitalAccess::ownerId(Auth::user());
        $members = CapitalProfile::with(['user:id,first_name,last_name,display_name,email,image,invitation_expires_at', 'role:id,name'])
            ->where('capital_owner_id', $ownerId)->whereNotNull('role_id')->latest()->get()
            ->map(fn ($p) => [
                'id' => $p->user_id,
                'first_name' => $p->user?->first_name,
                'last_name' => $p->user?->last_name,
                'email' => $p->user?->email,
                'image' => $p->user?->image,
                'role_id' => $p->role_id,
                'role' => $p->role?->name,
                'status' => ! $p->active ? 'deactivated' : ($p->user?->invitation_expires_at ? 'pending' : 'active'),
            ])->values();

        return response()->json(['members' => $members]);
    }

    /**
     * POST capital/invite-user {first_name, last_name?, email, role_id: 10001 super_admin | 10002 editor | 10003 viewer}
     * (owner / super admin): creates the team member's account and emails them a link to set their password
     * (accepted at POST organization-invitations/accept, the same page as program teams).
     */
    public function invite_user(Request $request)
    {
        $me = Auth::user();
        if (! OrganizationPermission::isAdmin($me)) {
            return OrganizationPermission::denied('Only the organization owner or a super admin can invite team members.');
        }
        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'role_id' => 'required|integer|in:10001,10002,10003',
        ], ['email.unique' => 'This email already has a Tujitume account.']);

        $ownerId = CapitalAccess::ownerId($me);
        $owner = User::with('capital_profile')->find($ownerId);
        $token = Str::random(64);

        try {
            $member = DB::transaction(function () use ($data, $owner, $token) {
                $member = User::create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'] ?? null,
                    'email' => $data['email'],
                    'password' => null,
                    'user_type_id' => 5,
                    'invited_at' => now(),
                    'invitation_token_hash' => hash('sha256', $token),
                    'invitation_expires_at' => now()->addDays(7),
                ]);
                CapitalProfile::create([
                    'user_id' => $member->id,
                    'role_id' => $data['role_id'],
                    'capital_owner_id' => $owner->id,
                    'org_name' => $owner->capital_profile?->org_name,
                    'org_type' => $owner->capital_profile?->org_type,
                    'active' => 1,
                ]);
                \App\Models\Auth\UserSetting::create(['user_id' => $member->id, 'timezone' => \App\Support\ProgramSchedule::deviceZone()]);

                return $member;
            });
        } catch (\Throwable $e) {
            return $this->failed($e);
        }

        $emailSent = true;
        try {
            $url = \App\Service\Notification\RecipientUrl::url('organization-invitations/accept?token='.$token, $member);
            $orgName = $owner->capital_profile?->org_name ?: $owner->display_name ?: $owner->first_name;
            Mail::send('capital.team_invitation', [
                'orgName' => $orgName,
                'firstName' => $member->first_name,
                'role' => \App\Models\Auth\Role::find($data['role_id'])?->name,
                'url' => $url,
            ], function ($msg) use ($member, $orgName) {
                $msg->to($member->email)->subject("You've been invited to join {$orgName} on Tujitume");
            });
        } catch (\Throwable $e) {
            $emailSent = false;
            ErrorLogService::report($e, ['capital_invite' => $member->id]);
        }

        return response()->json([
            'message' => $emailSent ? 'Team member invited.' : 'Team member added, but the invitation email could not be sent.',
            'email_sent' => $emailSent,
            'member' => ['id' => $member->id, 'email' => $member->email, 'first_name' => $member->first_name, 'role_id' => (int) $data['role_id'], 'status' => 'pending'],
        ], 201);
    }

    public function update_user(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'first_name' => 'required|string|max:255',
                'role_id' => 'required|numeric',
            ]);

            $user = User::findOrFail($request->id);
            if ($refusal = $this->capitalTeamRefusal($user)) {
                return $refusal;
            }
            if (! in_array((int) $request->role_id, [10001, 10002, 10003], true)) {
                return response()->json(['message' => 'Choose a team role.', 'errors' => ['role_id' => ['Choose a team role.']]], 422);
            }

            // Only the name and the role change here (never the email, password, account type or other account fields)
            $user->update($request->only(['first_name', 'last_name']));
            CapitalProfile::where('user_id', $user->id)->update(['role_id' => $request->role_id]);

            return response()->json(['message' => 'User updated.'], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Team member not found in your organization.'], 404);
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /** POST capital/delete-user {id}: the capital owner deletes their own account (and their team, offers, ...) */
    public function delete_user(Request $request)
    {
        $user = User::find($request->id);
        $capitalProfile = $user?->capital_profile;
        if (! $user || ! $capitalProfile || Auth::id() !== (int) $capitalProfile->user_id || $capitalProfile->role_id !== null) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->balance?->balance > 0) {
            return response(['message' => 'Account deletion not allowed. Please withdraw your balance first.'], 400);
        }
        $checker = new AccountDeletionEligibilityService($user);
        if (! $checker->isDeletable()) {
            return response()->json(['message' => 'Account deletion is not allowed', 'reasons' => $checker->preventingReason()], 400);
        }

        DB::beginTransaction();
        try {
            BalanceLog::where('changed_by', $user->id)->delete();
            StartupPitches::where('user_id', $user->id)->delete();

            if (CapitalAccess::isCapital($user)) {
                foreach (CapitalProfile::where('capital_owner_id', $user->id)->get() as $profile) {
                    $profile->user?->delete();
                    $profile->delete();
                }
                foreach (CapitalOffer::where('user_id', $user->id)->get() as $capital) {
                    CapitalFiles::delete($capital->offer_brief_file);
                    $capital->delete();
                }
                ServiceBooking::where('booker_id', $user->id)->delete();
                Messages::where('to_id', $user->id)->orWhere('from_id', $user->id)->delete();
                $capitalProfile->delete();
            }
            $user->delete();

            DB::commit();

            return response()->json(['message' => 'Account deleted.'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failed($e);
        }
    }

    /**
     * Why the signed-in person may not change or remove this capital team member, or null when they may: only the
     * owner and super admins, only their own team, never themselves or the owner.
     */
    private function capitalTeamRefusal(?User $user)
    {
        $me = Auth::user();
        $mine = $me?->capital_profile;
        $theirs = $user?->capital_profile;
        $ownerId = $mine ? ($mine->role_id === null ? $me->id : $mine->capital_owner_id) : null;

        if (! $user || ! $theirs || ! $ownerId || (int) $theirs->capital_owner_id !== (int) $ownerId) {
            return response()->json(['message' => 'Team member not found in your organization.'], 404);
        }
        if (! OrganizationPermission::isAdmin($me)) {
            return OrganizationPermission::denied('Only the organization owner or a super admin can manage team members.');
        }
        if ((int) $user->id === (int) $me->id || $theirs->role_id === null) {
            return response()->json(['message' => "You can't change yourself or the organization owner here."], 422);
        }

        return null;
    }

    public function delete_roleUser(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = User::find($request->id);
            if ($refusal = $this->capitalTeamRefusal($user)) {
                DB::rollBack();

                return $refusal;
            }
            User::where('id', $request->id)->delete();

            if ($user->user_type_id == 4) { // Program organization
                $user->organizationRole()->delete();
            } else {
                CapitalProfile::where('user_id', $request->id)->delete();
            }

            DB::commit();

            return response()->json(['message' => 'User deleted'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failed($e);
        }
    }

    // ─── Terms agreements ────────────────────────────────────────────────

    /** The application, for either side of it (provider and team, or the entrepreneur), with which side the caller is */
    private function pitchForParty($pitchId): array
    {
        $pitch = StartupPitches::with('capital_offer')->find($pitchId);
        if (! $pitch || ! $pitch->capital_offer) {
            return [null, null];
        }
        $user = Auth::user();
        $side = CapitalAccess::isProviderOf($user, $pitch) ? 'provider' : (CapitalAccess::isApplicantOf($user, $pitch) ? 'applicant' : null);

        return [$pitch, $side];
    }

    /**
     * GET capital/terms-agreements/{pitch_id}: every offer and counter-offer, newest first (`latest` marks the one in
     * play), for either side.
     */
    public function terms_agreements($pitch_id)
    {
        [$pitch, $side] = $this->pitchForParty($pitch_id);
        if (! $pitch) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        if (! $side) {
            return $this->forbidden('These terms are not yours.');
        }
        $agreements = CapitalTermsAgreement::with('capital:id,offer_title')->where('pitch_id', $pitch->id)->latest()->orderByDesc('id')->get();
        foreach ($agreements as $i => $agreement) {
            $agreement->latest = $i === 0;
            // stored since the version was made; older rows fall back to the status
            $agreement->proposed_by = $agreement->proposed_by ?: ($agreement->status === 'counter_submitted' ? 'applicant' : 'provider');
        }
        $latest = $agreements->first();

        return response()->json([
            'agreements' => $agreements,
            'viewer_side' => $side,
            // whose move it is on the newest one: respond (accept / reject) or propose
            'can_respond' => $latest && in_array($latest->status, ['submitted', 'counter_submitted'], true)
                && (($latest->status === 'submitted' && $side === 'applicant') || ($latest->status === 'counter_submitted' && $side === 'provider')),
            'can_propose' => (int) $pitch->status === StartupPitches::STATUS_ACCEPTED && (! $latest || $latest->status !== 'accepted')
                && ($side === 'provider' || ($latest !== null && $latest->status !== 'counter_submitted')),
            'terms_agreed' => (bool) $pitch->terms_agreed,
        ], 200);
    }

    /**
     * POST capital/terms-agreements (multipart) {pitch_id, amount?, terms?, document?}
     *   provider → an offer (status submitted; document required)
     *   entrepreneur → a counter-offer to the provider's offer (status counter_submitted; terms text required, document
     *   optional)
     */
    public function terms_agreements_store(Request $request)
    {
        $request->validate(['pitch_id' => ['required', 'integer']]);
        [$pitch, $side] = $this->pitchForParty($request->pitch_id);
        if (! $pitch) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        if (! $side) {
            return $this->forbidden('These terms are not yours.');
        }
        if ((int) $pitch->status !== StartupPitches::STATUS_ACCEPTED) {
            return response()->json(['message' => 'Terms can be proposed once the application is accepted.'], 422);
        }
        $latest = CapitalTermsAgreement::where('pitch_id', $pitch->id)->latest()->orderByDesc('id')->first();
        if ($latest?->status === 'accepted') {
            return response()->json(['message' => 'The terms are already agreed.'], 422);
        }
        if ($side === 'applicant' && ! $latest) {
            return response()->json(['message' => 'The capital provider has not proposed terms yet.'], 422);
        }
        if ($side === 'applicant' && $latest->status === 'counter_submitted') {
            return response()->json(['message' => 'Your counter-offer is waiting for the capital provider.'], 422);
        }

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'terms' => [$side === 'applicant' ? 'required' : 'nullable', 'string', 'max:10000'],
            'document' => [$side === 'provider' ? 'required' : 'nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);
        $status = $side === 'provider' ? 'submitted' : 'counter_submitted';

        $path = null;
        try {
            $path = CapitalFiles::store($request->file('document'), 'files/capitalAgreements/'.$pitch->id);
            $agreement = CapitalTermsAgreement::create([
                'capital_id' => $pitch->capital_id,
                'pitch_id' => $pitch->id,
                'business_owner_id' => $pitch->user_id,
                'capital_owner_id' => $pitch->capital_owner_id,
                'document' => $path,
                'amount' => $data['amount'] ?? null,
                'terms' => $data['terms'] ?? null,
                'status' => $status,
                'proposed_by' => $side,
            ]);
        } catch (\Throwable $e) {
            CapitalFiles::delete($path);

            return $this->failed($e);
        }

        $mail = ['capital' => $pitch->capital_offer->offer_title, 'startup' => $pitch->startup_name, 'status' => $status];
        if ($side === 'provider') {
            CapitalNotifier::send([$pitch->user_id], (int) $pitch->capital_owner_id,
                'The capital provider proposed terms for your application '.$pitch->startup_name.' to '.$pitch->capital_offer->offer_title.'.',
                CapitalNotifier::applicantDeal($pitch->id, 'terms'), 'opportunities.agreement_mail', $mail, 'Terms Agreement Offer');
        } else {
            CapitalNotifier::send(CapitalAccess::providerRecipients((int) $pitch->capital_owner_id), (int) $pitch->user_id,
                $pitch->startup_name.' sent a counter-offer for '.$pitch->capital_offer->offer_title.'.',
                CapitalNotifier::providerDeal($pitch->id, 'terms'), 'opportunities.agreement_mail', $mail, 'Terms Counter-Offer');
        }

        return response()->json(['message' => 'Offer submitted success.', 'agreement' => $agreement->fresh()], 200);
    }

    /**
     * POST capital/terms-agreements/update (multipart) {agreement_id, status: accepted|rejected, document?, reason?}:
     * the other side answers the newest offer. Accepting needs the signed document and marks the terms agreed;
     * rejecting keeps the offer's document and stores the reason.
     */
    public function terms_agreements_update(Request $request)
    {
        $request->validate([
            'agreement_id' => ['required', 'integer'],
            'status' => ['required', 'in:accepted,rejected'],
        ]);
        $agreement = CapitalTermsAgreement::find($request->agreement_id);
        if (! $agreement) {
            return response()->json(['message' => 'Terms agreement not found.'], 404);
        }
        [$pitch, $side] = $this->pitchForParty($agreement->pitch_id);
        if (! $pitch) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        if (! $side) {
            return $this->forbidden('These terms are not yours.');
        }
        $latestId = CapitalTermsAgreement::where('pitch_id', $pitch->id)->latest()->orderByDesc('id')->value('id');
        if ((int) $latestId !== (int) $agreement->id || ! in_array($agreement->status, ['submitted', 'counter_submitted'], true)) {
            return response()->json(['message' => 'This offer has already been answered or replaced.'], 422);
        }
        $responder = $agreement->status === 'submitted' ? 'applicant' : 'provider';
        if ($side !== $responder) {
            return $this->forbidden('The other side answers this offer.');
        }

        $accepted = $request->status === 'accepted';
        $data = $request->validate([
            'document' => [$accepted ? 'required' : 'nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $path = null;
        try {
            DB::transaction(function () use ($agreement, $pitch, $accepted, $data, $request, &$path) {
                $path = CapitalFiles::store($request->file('document'), 'files/capitalAgreements/'.$pitch->id);
                $agreement->update(array_filter([
                    'status' => $request->status,
                    'reason' => $data['reason'] ?? null,
                    'document' => $path, // only a new file replaces it (never null on reject)
                ], fn ($v) => $v !== null));
                $pitch->update(['terms_agreed' => $accepted ? 1 : 0]);
            });
        } catch (\Throwable $e) {
            CapitalFiles::delete($path);

            return $this->failed($e);
        }

        $word = $accepted ? 'accepted' : 'rejected';
        $mail = ['capital' => $pitch->capital_offer->offer_title, 'startup' => $pitch->startup_name, 'status' => $request->status];
        $text = 'Your terms offer for '.$pitch->startup_name.' / '.$pitch->capital_offer->offer_title.' was '.$word.'.'
            .(! $accepted && ! empty($data['reason']) ? ' Reason: '.$data['reason'] : '');
        if ($responder === 'applicant') {
            CapitalNotifier::send(CapitalAccess::providerRecipients((int) $pitch->capital_owner_id), (int) $pitch->user_id, $text,
                CapitalNotifier::providerDeal($pitch->id, 'terms'), 'opportunities.agreement_mail', $mail, 'Terms Agreement '.ucfirst($word));
        } else {
            CapitalNotifier::send([$pitch->user_id], (int) $pitch->capital_owner_id, $text,
                CapitalNotifier::applicantDeal($pitch->id, 'terms'), 'opportunities.agreement_mail', $mail, 'Terms Agreement '.ucfirst($word));
        }

        return response()->json(['message' => $accepted ? 'Offer accepted.' : 'Offer rejected.', 'agreement' => $agreement->fresh()], 200);
    }

    // H E L P E R S
    public function get_role()
    {
        $user = Auth::user()->load('capital_profile.role');
        $user->role = $user->capital_profile?->role?->name ?? 'super-admin';
        $user->capital_owner_id = $user->capital_profile?->capital_owner_id;

        return $user;
    }
}
