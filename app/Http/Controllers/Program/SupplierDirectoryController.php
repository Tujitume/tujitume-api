<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\SupplierDirectory;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\EmailBrand;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierDirectoryController extends Controller
{
    /**
     * List all suppliers for authenticated user
     * GET /program/supplier-directory
     */
    public function index(Request $request)
    {
        $userId = auth()->id();

        try {
            $query = SupplierDirectory::where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhere('added_by', $userId);
            });

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Filter by supplier type
            if ($request->has('supplier_type')) {
                $query->where('supplier_type', $request->supplier_type);
            }

            // Search by name, email, or phone
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('legal_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%');
                });
            }

            // Order by name
            $suppliers = $query->orderBy('legal_name')->paginate(20);
            $this->attachOnboardingStatus($suppliers->getCollection());

            return response()->json($suppliers, 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Get single supplier details
     * GET /program/supplier-directory/{supplier_id}
     */
    public function show($supplierId)
    {
        $userId = auth()->id();

        try {
            $supplier = SupplierDirectory::where('user_id', $userId)
                ->findOrFail($supplierId);

            $this->attachOnboardingStatus(collect([$supplier]));

            return response()->json(['data' => $supplier], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * List milestones assigned to a supplier owned by the authenticated program user.
     * GET /program/supplier-directory/{supplierId}/assigned-milestones
     */
    public function assignedMilestones(Request $request, $supplierId)
    {
        try {
            $supplier = SupplierDirectory::where('user_id', auth()->id())
                ->findOrFail($supplierId);

            $assignments = $supplier->milestoneAssignments()
                ->with([
                    'milestone.budgetItems',
                    'milestone.application.program',
                    'milestone.application.business',
                ])
                ->latest()
                ->paginate($request->integer('per_page', 20));

            return response()->json($assignments, 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);
        } catch (\Exception $e) {
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Create new supplier
     * POST /program/supplier-directory
     */
    public function store(Request $request)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $validated = $request->validate([

                // Basic Identity
                'legal_name' => 'required|string|max:255',
                'contact_person' => 'nullable|string|max:150',
                'phone' => 'required|string|max:30',
                'email' => 'required|email|max:191',
                'supplier_type' => 'nullable|string|max:100',
                // Payment Method
                'payment_method' => 'required|in:mpesa_mobile,mpesa_lipr,mpesa_paybill,mpesa_till,bank_transfer,other',

                // LIPR
                'lipr_wallet' => [
                    'nullable','numeric',  
                ],

                'lipr_mobile_number' => [
                    'nullable',
                    'string',
                    'max:30',
                ],

                // Paybill
                'mpesa_paybill_number' => [
                    'nullable',
                    'string',
                    'max:20',
                    'required_if:payment_method,mpesa_paybill',
                ],

                'mpesa_paybill_account' => [
                    'nullable',
                    'string',
                    'max:20',
                    'required_if:payment_method,mpesa_paybill',
                ],

                // Till
                'mpesa_till_number' => [
                    'nullable',
                    'string',
                    'max:20',
                    'required_if:payment_method,mpesa_till',
                ],

                'mpesa_account_reference' => [
                    'nullable',
                    'string',
                    'max:100',
                    'required_if:payment_method,mpesa_till',
                ],

                // Bank
                'bank_name' => [
                    'nullable',
                    'string',
                    'max:100',
                    'required_if:payment_method,bank_transfer',
                ],

                'bank_account_number' => [
                    'nullable',
                    'string',
                    'max:50',
                    'required_if:payment_method,bank_transfer',
                ],

                'bank_branch' => 'nullable|string|max:100',
                'bank_swift_code' => 'nullable|string|max:20',

                // Other
                'notes' => 'nullable|string',
                'is_active' => 'nullable|boolean',

                'city' => 'nullable|string',
                'country' => 'nullable|string',
                'address' => 'nullable|string',
            ]);

            //check if $validated['email']

            if ($validated['payment_method'] === 'mpesa_lipr' && empty($validated['lipr_wallet'])) {
                return response()->json([
                    'message' => 'lipr_wallet is required when payment method is mpesa_lipr.'
                ], 422);
            }

            if ($validated['payment_method'] === 'mpesa_mobile' && empty($validated['lipr_mobile_number'])) {
                return response()->json([
                    'message' => 'lipr_mobile_number is required when payment method is mpesa_mobile.'
                ], 422);
            }

            // Add added_by
            $validated['added_by'] = $userId;

            // Create supplier
            $supplier = SupplierDirectory::create($validated);

            // Sent as the adding organisation, not as Tujitume
            $adder = auth()->user();
            $orgName = $this->orgName($adder);
            (new EmailService())->send(
                "{$orgName} has added you as a supplier",
                'programs.supplier_welcome',
                [
                    'brand'          => EmailBrand::forUser($adder),
                    'recipientName'  => $validated['contact_person'] ?? $validated['legal_name'],
                    'added_by'       => trim($adder->first_name . ' ' . $adder->last_name),
                    'org_name'       => $orgName,
                    'supplier_name'  => $validated['legal_name'],
                    'supplier_type'  => $validated['supplier_type'] ?? null,
                ],
                $validated['email']
            );

            $this->notifySupplier($supplier, "{$orgName} added you as a supplier on Tujitume.");

            DB::commit();

            return response()->json([
                'message' => 'Supplier added successfully',
                'data' => tap($supplier, fn ($sup) => $this->attachOnboardingStatus(collect([$sup]))),
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Update supplier
     * PATCH /program/supplier-directory/{supplier_id}
     */
    public function update(Request $request, $supplierId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $supplier = SupplierDirectory::where('id', $supplierId)
                ->findOrFail($supplierId);

            $validated = $request->validate([
                // Basic Identity
                'legal_name' => 'sometimes|string|max:255',
                'contact_person' => 'nullable|string|max:150',
                'phone' => 'nullable|string|max:30',
                'email' => 'nullable|email|max:191',
                'supplier_type' => 'nullable|string|max:100',

                // Payment Method
                'payment_method' => 'nullable|in:mpesa_lipr,mpesa_paybill,mpesa_till,mpesa_mobile,bank_transfer,other',

                // LIPR Details
                'lipr_wallet' => 'nullable|string|max:30',
                'lipr_mobile_number' => 'nullable|string|max:30',

                // M-Pesa Paybill
                'mpesa_paybill_number' => 'nullable|string|max:20',
                'mpesa_paybill_account' => 'nullable|string|max:20',

                // M-Pesa Till
                'mpesa_till_number' => 'nullable|string|max:20',

                // M-Pesa General
                'mpesa_account_reference' => 'nullable|string|max:100',

                // Bank Details
                'bank_name' => 'nullable|string|max:100',
                'bank_account_number' => 'nullable|string|max:50',
                'bank_branch' => 'nullable|string|max:100',
                'bank_swift_code' => 'nullable|string|max:20',

                // Internal
                'notes' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);

            $before = $supplier->only(self::PAYMENT_FIELDS);
            $wasActive = (bool) $supplier->is_active;

            $supplier->update($validated);

            DB::commit();

            $this->notifyUpdate($supplier->fresh(), $before, $wasActive);

            return response()->json([
                'message' => 'Supplier updated successfully',
                'data' => tap($supplier->fresh(), fn ($sup) => $this->attachOnboardingStatus(collect([$sup]))),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Delete (archive) supplier
     * DELETE /program/supplier-directory/{supplier_id}
     */
    public function destroy($supplierId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $supplier = SupplierDirectory::where('id', $supplierId)
                ->findOrFail($supplierId);

            // Check if supplier is assigned to any milestones
            $assignedCount = DB::table('milestone_suppliers')
                ->where('supplier_id', $supplierId)
                ->count();

            if ($assignedCount > 0) {
                return response()->json([
                    'error' => "Supplier is assigned to {$assignedCount} milestone(s) and cannot be deleted. You can deactivate instead."
                ], 422);
            }

            // Safe to delete
            $supplier->delete();

            DB::commit();

            return response()->json([
                'message' => 'Supplier deleted successfully'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Deactivate supplier (soft archive)
     * POST /program/supplier-directory/{supplier_id}/deactivate
     */
    public function deactivate($supplierId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $supplier = SupplierDirectory::where('id', $supplierId)
                ->findOrFail($supplierId);

            $supplier->update(['is_active' => false]);

            DB::commit();

            $this->notifyStatusChange($supplier, false);

            return response()->json([
                'message' => 'Supplier deactivated successfully',
                'data' => $supplier->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Reactivate supplier
     * POST /program/supplier-directory/{supplier_id}/activate
     */
    public function activate($supplierId)
    {
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $supplier = SupplierDirectory::where('id', $supplierId)
                ->findOrFail($supplierId);

            $supplier->update(['is_active' => true]);

            DB::commit();

            $this->notifyStatusChange($supplier, true);

            return response()->json([
                'message' => 'Supplier activated successfully',
                'data' => $supplier->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Supplier not found'], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    private const PAYMENT_FIELDS = [
        'payment_method', 'lipr_wallet', 'lipr_mobile_number',
        'mpesa_paybill_number', 'mpesa_paybill_account', 'mpesa_till_number', 'mpesa_account_reference',
        'bank_name', 'bank_account_number', 'bank_branch', 'bank_swift_code',
    ];

    private const PAYMENT_LABELS = [
        'mpesa_lipr'    => 'Tujitume Wallet Transfer',
        'mpesa_paybill' => 'M-Pesa Paybill',
        'mpesa_till'    => 'M-Pesa Till',
        'mpesa_mobile'  => 'M-Pesa Mobile',
        'bank_transfer' => 'Bank Transfer',
        'other'         => 'Other',
    ];

    /**
     * Adds `status` to each supplier: "onboarded" once the supplier has a Tujitume
     * account (matched by email), otherwise "invited". One query for the whole page.
     */
    private function attachOnboardingStatus($suppliers): void
    {
        $emails = $suppliers->pluck('email')->filter()->map(fn ($e) => strtolower($e))->unique()->values()->all();
        $registered = [];
        if ($emails) {
            foreach (User::whereIn(DB::raw('LOWER(email)'), $emails)->get(['id', 'email']) as $user) {
                $registered[strtolower($user->email)] = $user->id;
            }
        }

        foreach ($suppliers as $supplier) {
            $accountId = $supplier->user_id ?: ($registered[strtolower((string) $supplier->email)] ?? null);
            $supplier->setAttribute('status', $accountId ? 'onboarded' : 'invited');
            $supplier->setAttribute('onboarded', (bool) $accountId);
        }
    }

    private function orgName($user): string
    {
        return $user->organization?->display_name
            ?: $user->organization?->name
            ?: $user->display_name
            ?: trim($user->first_name . ' ' . $user->last_name);
    }

    private function supplierAccountId(SupplierDirectory $supplier)
    {
        return $supplier->user_id
            ?: User::whereRaw('LOWER(email) = ?', [strtolower((string) $supplier->email)])->value('id');
    }

    /** In-app notification for the supplier, when they already have an account. */
    private function notifySupplier(SupplierDirectory $supplier, string $text): void
    {
        try {
            if ($receiverId = $this->supplierAccountId($supplier)) {
                (new NotificationService())->create($receiverId, auth()->id(), $text, 'overview/account', 'supplier');
            }
        } catch (\Throwable $e) {
            ErrorLogService::report($e);
        }
    }

    /** Email (branded as the organisation) and in-app notification telling the supplier what changed. */
    private function notifyChange(SupplierDirectory $supplier, string $subject, string $title, string $message, array $details = []): void
    {
        $adder = auth()->user();

        (new EmailService())->send(
            $subject,
            'programs.supplier_update',
            [
                'brand'         => EmailBrand::forUser($adder),
                'title'         => $title,
                'recipientName' => $supplier->contact_person ?: $supplier->legal_name,
                'org_name'      => $this->orgName($adder),
                'summary'       => $message,
                'details'       => $details,
                'onboarded'     => (bool) $this->supplierAccountId($supplier),
            ],
            $supplier->email
        );

        $this->notifySupplier($supplier, $message);
    }

    private function notifyStatusChange(SupplierDirectory $supplier, bool $active): void
    {
        try {
            $orgName = $this->orgName(auth()->user());

            $this->notifyChange(
                $supplier,
                $active ? "{$orgName} reactivated your supplier profile" : "{$orgName} deactivated your supplier profile",
                $active ? 'Supplier profile reactivated' : 'Supplier profile deactivated',
                $active
                    ? "{$orgName} has reactivated {$supplier->legal_name}. You can receive payments from them again."
                    : "{$orgName} has deactivated {$supplier->legal_name}. You will not receive new payments from them until it is reactivated.",
                ['Supplier' => $supplier->legal_name, 'Status' => $active ? 'Active' : 'Inactive']
            );
        } catch (\Throwable $e) {
            ErrorLogService::report($e);
        }
    }

    private function notifyUpdate(SupplierDirectory $supplier, array $before, bool $wasActive): void
    {
        try {
            if ($wasActive !== (bool) $supplier->is_active) {
                $this->notifyStatusChange($supplier, (bool) $supplier->is_active);
            }

            if ($before != $supplier->only(self::PAYMENT_FIELDS)) {
                $orgName = $this->orgName(auth()->user());
                $this->notifyChange(
                    $supplier,
                    "{$orgName} updated your payment details",
                    'Payment details updated',
                    "{$orgName} has updated the payment details on file for {$supplier->legal_name}. If this is not what you expected, please contact them.",
                    array_merge(['Supplier' => $supplier->legal_name], $this->describePayment($supplier))
                );
            }
        } catch (\Throwable $e) {
            ErrorLogService::report($e);
        }
    }

    /** Payment details for the email, with account numbers masked to the last four digits. */
    private function describePayment(SupplierDirectory $s): array
    {
        $mask = fn ($v) => $v ? str_repeat('•', max(strlen($v) - 4, 0)) . substr($v, -4) : null;

        $rows = ['Payment method' => self::PAYMENT_LABELS[$s->payment_method] ?? $s->payment_method];
        $rows += match ($s->payment_method) {
            'mpesa_mobile'  => ['Mobile number' => $mask($s->lipr_mobile_number)],
            'mpesa_lipr'    => ['Tujitume wallet' => $mask($s->lipr_wallet)],
            'mpesa_paybill' => ['Paybill number' => $s->mpesa_paybill_number, 'Account number' => $mask($s->mpesa_paybill_account)],
            'mpesa_till'    => ['Till number' => $s->mpesa_till_number],
            'bank_transfer' => ['Bank' => $s->bank_name, 'Account number' => $mask($s->bank_account_number), 'Branch' => $s->bank_branch],
            default         => [],
        };

        return array_filter($rows, fn ($v) => $v !== null && $v !== '');
    }

    public function assignToMilestone(Request $request, ProgramMilestone $milestone)
    {
        DB::beginTransaction();

        try {
            if(!$milestone->application){
                return response()->json([
                    'message' => 'No application found for this milestone.'
                ], 404);
            }
            $applicantId = $milestone->application->user_id;

            $addedBy = 'program_owner';

            // 🔐 Authorization - only program owner or applicant can assign suppliers to a milestone
            if ($applicantId === auth()->id()) {

                $allowedEdits = $milestone->allowed_edits ?? [];

                $addedBy = 'applicant';

                if (!in_array('can_add_suppliers', $allowedEdits)) {
                    return response()->json([
                        'message' => 'You are not permitted to add suppliers to this milestone'
                    ], 403);
                }
            }


            // ✅ Validation
            $validated = $request->validate([
                'supplier_id' => ['required', 'integer', 'exists:supplier_directories,id' ],
                'payment_route' => ['required', Rule::in(['direct_to_supplier', 'split', 'direct_to_applicant'])
                ],
                'quoted_amount' => ['required', 'numeric'],

                'assignment_type' => ['nullable', Rule::in(['primary', 'approved', 'preferred']) ],
            ]);

            $exists = MilestoneSupplier::where('milestone_id', $milestone->id)
                ->where('supplier_id', $validated['supplier_id'])->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supplier already assigned to this milestone'
                ], 422);
            }

            // Update record
            $supplier = MilestoneSupplier::create([
                'milestone_id'   => $milestone->id,
                'supplier_id'     =>$validated['supplier_id'],
                'assignment_type'=> $validated['assignment_type'] ?? 'approved',
                'payment_route'  => $validated['payment_route'],
                'quoted_amount'      => $validated['quoted_amount'],
                'added_by'       => $addedBy,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Supplier assigned successfully',
                'data' => $supplier
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            ErrorLogService::report($e);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

}
