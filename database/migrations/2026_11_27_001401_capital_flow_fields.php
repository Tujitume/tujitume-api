<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The capital flow (capital provider = user type 5):
 *  - capital_profiles: what a capital provider fills in at sign-up (organization name, investment and turnover range,
 *    preferred sectors, social impact areas, the investment guidelines file).
 *  - capital_milestones: fund_released / released_at / release_reference (both release paths wrote fund_released, which
 *    did not exist) plus release_status and fee_amount so a release is recorded before any money moves.
 *  - capital_terms_agreements: the counter-offer text (terms).
 *  - startup_pitches: why an application was rejected, and when the entrepreneur last asked for the next tranche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capital_profiles', function (Blueprint $table) {
            foreach ([
                'org_name' => fn () => $table->string('org_name')->nullable()->after('capital_owner_id'),
                'inv_range' => fn () => $table->json('inv_range')->nullable(),
                'turnover_range' => fn () => $table->json('turnover_range')->nullable(),
                'sectors' => fn () => $table->json('sectors')->nullable(),
                'social_impact_areas' => fn () => $table->json('social_impact_areas')->nullable(),
                'investment_guidelines' => fn () => $table->string('investment_guidelines', 500)->nullable(),
            ] as $column => $add) {
                if (! Schema::hasColumn('capital_profiles', $column)) {
                    $add();
                }
            }
        });

        Schema::table('capital_milestones', function (Blueprint $table) {
            if (! Schema::hasColumn('capital_milestones', 'fund_released')) {
                $table->boolean('fund_released')->default(false);
            }
            if (! Schema::hasColumn('capital_milestones', 'release_status')) {
                // null (not started) | processing (recorded, money call under way) | released | failed
                $table->string('release_status', 20)->nullable();
            }
            if (! Schema::hasColumn('capital_milestones', 'released_at')) {
                $table->timestamp('released_at')->nullable();
            }
            if (! Schema::hasColumn('capital_milestones', 'release_reference')) {
                $table->string('release_reference')->nullable();
            }
            if (! Schema::hasColumn('capital_milestones', 'fee_amount')) {
                $table->decimal('fee_amount', 15, 2)->nullable();
            }
        });

        Schema::table('capital_terms_agreements', function (Blueprint $table) {
            if (! Schema::hasColumn('capital_terms_agreements', 'terms')) {
                $table->text('terms')->nullable();
            }
        });

        Schema::table('startup_pitches', function (Blueprint $table) {
            if (! Schema::hasColumn('startup_pitches', 'rejection_reason')) {
                $table->string('rejection_reason', 1000)->nullable();
            }
            if (! Schema::hasColumn('startup_pitches', 'fund_requested_at')) {
                $table->timestamp('fund_requested_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $drop = function (string $table, array $columns) {
            $existing = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($existing) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($existing));
            }
        };
        $drop('capital_profiles', ['org_name', 'inv_range', 'turnover_range', 'sectors', 'social_impact_areas', 'investment_guidelines']);
        $drop('capital_milestones', ['fund_released', 'release_status', 'released_at', 'release_reference', 'fee_amount']);
        $drop('capital_terms_agreements', ['terms']);
        $drop('startup_pitches', ['rejection_reason', 'fund_requested_at']);
    }
};
