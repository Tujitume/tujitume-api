<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
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
        // The public_id column is defined by the original programs-table migration.
    }
};
