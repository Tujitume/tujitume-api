<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ValidationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * POST register/check-duplicate (public, throttled)
     * Before an account is created: is there already an account for this person (same first name, last name and date of
     * birth)? Only then is a hint returned, and it is deliberately small: the first name, the initial of the last name
     * and a partly hidden email. The date of birth and every other detail are never sent back.
     */
    public function checkDuplicate(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'dob' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        $dob = $this->normalizeDob($data['dob']);
        if ($dob === null) {
            return response()->json(['match' => false]);
        }

        $first = $this->normalizeName($data['first_name']);
        $last = $this->normalizeName($data['last_name']);

        $user = \App\Models\Auth\User::query()
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$first])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$last])
            ->whereNotNull('dob')
            ->limit(25)
            ->get(['id', 'first_name', 'last_name', 'dob', 'email'])
            ->first(fn ($candidate) => $this->normalizeDob((string) $candidate->dob) === $dob);

        if (! $user) {
            return response()->json(['match' => false]);
        }

        return response()->json([
            'match' => true,
            'account' => [
                'name' => trim($user->first_name.' '.mb_substr((string) $user->last_name, 0, 1).'.'),
                'email_hint' => $this->maskEmail((string) $user->email),
                // The person typed this email themselves, so saying it is the same one reveals nothing new
                'same_email' => ! empty($data['email']) && strcasecmp(trim($data['email']), (string) $user->email) === 0,
            ],
        ]);
    }

    private function normalizeName(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    /** Day, month and year in any of the common shapes (22-03-1995, 1995-03-22, 22/03/1995) as Y-m-d, or null. */
    private function normalizeDob(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** jane.doe@gmail.com becomes ja***e@gmail.com: enough for the owner to recognise it, not enough to use it. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $len = mb_strlen($local);
        $shown = $len <= 3 ? mb_substr($local, 0, 1).'***' : mb_substr($local, 0, 2).'***'.mb_substr($local, -1);

        return $shown.'@'.$domain;
    }

    public function checkAvailability(Request $request, string $field)
    {
        $definition = config("registration_validation.fields.{$field}");

        if (! $definition) {
            return response()->json(['message' => 'Unsupported availability field.'], 404);
        }

        $data = $request->validate([
            'value' => $definition['rules'],
        ]);

        $value = $this->normalize($data['value'], $definition['normalizer'] ?? null);
        $exists = DB::table($definition['table'])
            ->where($definition['column'], $value)
            ->exists();

        return response()->json([
            'field' => $field,
            'value' => $value,
            'available' => ! $exists,
        ]);
    }

    private function normalize(string $value, ?string $normalizer): string
    {
        $value = trim($value);

        return $normalizer === 'lowercase_trim' ? strtolower($value) : $value;
    }
}
