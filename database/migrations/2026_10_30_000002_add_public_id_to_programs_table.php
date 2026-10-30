<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('programs', 'public_id')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->ulid('public_id')->nullable()->default(null)->unique();
            });
        }

        DB::table('programs')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(500, function ($programs): void {
                foreach ($programs as $program) {
                    DB::table('programs')->where('id', $program->id)->update([
                        'public_id' => (string) Str::ulid(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('programs', 'public_id')) {
            Schema::table('programs', function (Blueprint $table): void {
                $table->dropUnique(['public_id']);
                $table->dropColumn('public_id');
            });
        }
    }
};
