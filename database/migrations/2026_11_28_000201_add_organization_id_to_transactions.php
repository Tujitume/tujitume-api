<?php

use App\Models\Finance\Transactions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transactions.organization_id: the organization whose wallet the money moved in or out of (owner, 28 Nov). The
 * organization wallet page lists these; My Wallet lists only a person's own rows (organization_id empty); My
 * Transactions lists both. Set on create by Transactions::organizationOf(); existing rows are filled the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'organization_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('recipient_id')->index();
            });
        }

        DB::table('transactions')->whereNull('organization_id')->orderBy('created_at')->select(['id', 'user_id', 'type', 'method', 'direction'])
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $organizationId = Transactions::organizationOf((array) $row);
                    if ($organizationId) DB::table('transactions')->where('id', $row->id)->update(['organization_id' => $organizationId]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'organization_id')) {
            Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn('organization_id'));
        }
    }
};
