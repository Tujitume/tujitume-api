<?php

namespace App\Models\Programs;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierDirectory extends Model
{
    protected $table = 'supplier_directories';

    protected $fillable = [
        'user_id',

        // Basic Identity
        'legal_name',
        'contact_person',
        'phone',
        'email',
        'supplier_type',

        // Payment Method
        'payment_method',

        // LIPR Details
        'lipr_wallet',
        'lipr_mobile_number',

        // M-Pesa Paybill
        'mpesa_paybill_number',
        'mpesa_paybill_account',

        // M-Pesa Till
        'mpesa_till_number',

        // M-Pesa General
        'mpesa_account_reference',

        // Bank Details
        'bank_name',
        'bank_account_number',
        'bank_branch',
        'bank_swift_code',

        // Internal
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the user (program owner) who owns this supplier
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get milestone assignments for this supplier
     */
    public function milestoneAssignments()
    {
        return $this->hasMany(MilestoneSupplier::class, 'supplier_id');
    }

    /**
     * Scope to get only active suppliers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get suppliers by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('supplier_type', $type);
    }

    /**
     * Scope to search suppliers
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('legal_name', 'like', '%' . $search . '%')
                ->orWhere('email', 'like', '%' . $search . '%')
                ->orWhere('phone', 'like', '%' . $search . '%');
        });
    }

    /**
     * Deactivate supplier
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Activate supplier
     */
    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    /**
     * Check if supplier is assigned to any milestones
     */
    public function isAssignedToMilestones(): bool
    {
        return $this->milestoneAssignments()->exists();
    }

    /**
     * Get payment method label
     */
    public function getPaymentMethodLabel(): string
    {
        return match($this->payment_method) {
            'mpesa_paybill' => 'M-Pesa Paybill',
            'mpesa_till' => 'M-Pesa Till',
            'mpesa_mobile' => 'M-Pesa Mobile',
            'bank_transfer' => 'Bank Transfer',
            'other' => 'Other',
            default => 'Not Set',
        };
    }

    /**
     * Get formatted payment details for display
     */
    public function getPaymentDetailsAttribute(): array
    {
        $details = [];

        switch ($this->payment_method) {
            case 'mpesa_paybill':
                if ($this->mpesa_paybill_number) {
                    $details['Paybill Number'] = $this->mpesa_paybill_number;
                }
                if ($this->mpesa_paybill_account) {
                    $details['Account Number'] = $this->mpesa_paybill_account;
                }
                break;

            case 'mpesa_till':
                if ($this->mpesa_till_number) {
                    $details['Till Number'] = $this->mpesa_till_number;
                }
                break;

            case 'mpesa_mobile':
                if ($this->lipr_mobile_number) {
                    $details['Mobile Number'] = $this->lipr_mobile_number;
                }
                if ($this->lipr_wallet) {
                    $details['Wallet'] = $this->lipr_wallet;
                }
                break;

            case 'bank_transfer':
                if ($this->bank_name) {
                    $details['Bank'] = $this->bank_name;
                }
                if ($this->bank_account_number) {
                    $details['Account Number'] = $this->bank_account_number;
                }
                if ($this->bank_branch) {
                    $details['Branch'] = $this->bank_branch;
                }
                if ($this->bank_swift_code) {
                    $details['SWIFT Code'] = $this->bank_swift_code;
                }
                break;
        }

        return $details;
    }
}
