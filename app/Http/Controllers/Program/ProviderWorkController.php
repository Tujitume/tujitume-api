<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\MilestoneVerification;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\SupplierDirectory;
use App\Models\Services\Services;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;

/**
 * Program work for Tujitume service providers: what organizations have asked them to do, and the list
 * organizations search when they want a third-party auditor or a supplier.
 */
class ProviderWorkController extends Controller
{
    /**
     * Everything assigned to the signed-in service provider.
     * GET /programs/provider/work?type=all|inspection|audit|supplier
     */
    public function index(Request $request)
    {
        $me = $request->user();

        if ((int) $me->user_type_id !== 3) {
            return response()->json(['message' => 'Program work is for service providers.'], 403);
        }

        try {
            $type = $request->query('type', 'all');
            $rows = collect();

            if (in_array($type, ['all', 'inspection'], true)) {
                $rows = $rows->concat($this->inspections($me));
            }
            if (in_array($type, ['all', 'audit'], true)) {
                $rows = $rows->concat($this->audits($me));
            }
            if (in_array($type, ['all', 'supplier'], true)) {
                $rows = $rows->concat($this->supplierOrders($me));
            }

            return response()->json([
                'data' => $rows->sortByDesc('sort')->values()->map(fn ($row) => collect($row)->except('sort')->all()),
            ], 200);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['user_id' => $me->id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /** Site visits an organization assigned to this provider (including third-party audit visits). */
    private function inspections(User $me)
    {
        return MESiteVisit::where('reviewer_id', $me->id)
            ->with(['checkpoint.application.program'])
            ->get()
            ->map(function (MESiteVisit $visit) {
                $application = $visit->checkpoint?->application;

                return [
                    'kind' => 'inspection',
                    'id' => $visit->id,
                    'reference' => 'SV-' . $visit->id,
                    'program_id' => $application?->program_id,
                    'program' => $application?->program?->program_title ?? 'Program',
                    'business' => $application?->startup_name,
                    'milestone' => $visit->checkpoint?->checkpoint_name,
                    'detail' => $visit->objective,
                    'location' => $visit->location,
                    'support_type' => $visit->assign_type === 'third_party_audit' ? 'Third-party audit' : 'Site visit',
                    'inspector' => $visit->inspector,
                    'amount' => null,
                    'status' => $visit->status,
                    'due' => $visit->start_date?->toDateString(),
                    'sort' => $visit->start_date?->timestamp ?? $visit->created_at?->timestamp ?? 0,
                ];
            });
    }

    /** MPRV audits an organization asked this provider to carry out. */
    private function audits(User $me)
    {
        return MilestoneVerification::where('auditor_id', $me->id)
            ->with(['milestone.application.program'])
            ->get()
            ->map(function ($verification) {
                $milestone = $verification->milestone;
                $application = $milestone?->application;

                return [
                    'kind' => 'audit',
                    'id' => $verification->id,
                    'reference' => 'AU-' . $verification->id,
                    'program_id' => $application?->program_id,
                    'program' => $application?->program?->program_title ?? 'Program',
                    'business' => $application?->startup_name,
                    'milestone' => $milestone?->title,
                    'detail' => null,
                    'location' => null,
                    'support_type' => 'MPRV audit',
                    'inspector' => null,
                    'amount' => null,
                    'status' => (string) ($verification->decision ?? 'audit_requested'),
                    'due' => null,
                    'sort' => $verification->audit_started_at?->timestamp ?? $verification->created_at?->timestamp ?? 0,
                ];
            });
    }

    /** Milestones where an organization (or applicant) named this provider as a supplier. */
    private function supplierOrders(User $me)
    {
        $directoryIds = SupplierDirectory::where('user_id', $me->id)
            ->orWhereRaw('LOWER(email) = ?', [strtolower((string) $me->email)])
            ->pluck('id');

        if ($directoryIds->isEmpty()) {
            return collect();
        }

        return MilestoneSupplier::whereIn('supplier_id', $directoryIds)
            ->with(['milestone.application.program'])
            ->get()
            ->map(function (MilestoneSupplier $order) {
                $milestone = $order->milestone;
                $application = $milestone?->application;

                return [
                    'kind' => 'supplier',
                    'id' => $order->id,
                    'reference' => 'SO-' . $order->id,
                    'program_id' => $application?->program_id,
                    'program' => $application?->program?->program_title ?? 'Program',
                    'business' => $application?->startup_name,
                    'milestone' => $milestone?->title,
                    'detail' => null,
                    'location' => null,
                    'support_type' => 'Supplier',
                    'inspector' => null,
                    'payment_route' => $order->payment_route,
                    'amount' => $order->quoted_amount !== null ? (float) $order->quoted_amount : null,
                    'status' => (string) (is_object($milestone?->status) ? ($milestone->status->value ?? 'pending') : ($milestone?->status ?? 'pending')),
                    'due' => $milestone?->estimated_completion_date ? (string) $milestone->estimated_completion_date : null,
                    'sort' => $order->created_at?->timestamp ?? 0,
                ];
            });
    }

    /**
     * Tujitume service providers an organization can ask for third-party audit or work.
     * GET /programs/third-party-providers?search=
     */
    public function providers(Request $request)
    {
        $me = $request->user();

        // Only organizations (and their team) look for providers; reviewers and other account types do not
        if ((int) $me->user_type_id !== 4) {
            return response()->json(['message' => 'Only organizations can look for service providers.'], 403);
        }

        try {
            $search = trim((string) $request->query('search', ''));

            $users = User::where('user_type_id', 3)
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%' . $search . '%';
                    $query->where(function ($q) use ($like) {
                        $q->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('display_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('city', 'like', $like);
                    });
                })
                ->orderBy('first_name')
                ->limit(30)
                ->get(['id', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'image', 'city', 'country']);

            $servicesByUser = Services::whereIn('user_id', $users->pluck('id'))
                ->get(['id', 'user_id', 'name', 'category'])
                ->groupBy('user_id');

            return response()->json([
                'data' => $users->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->display_name ?: trim($user->first_name . ' ' . $user->last_name),
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'image' => $user->image,
                    'location' => collect([$user->city, $user->country])->filter()->implode(', '),
                    'services' => ($servicesByUser->get($user->id) ?? collect())
                        ->map(fn ($service) => ['id' => $service->id, 'name' => $service->name, 'category' => $service->category])
                        ->values(),
                ])->values(),
            ], 200);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['search' => $request->query('search')]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
