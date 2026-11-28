<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Light / dark mode is one person's own choice (owner, 28 Nov), so the organization audit log no longer records it
 * (OrganizationAudit::PERSONAL_FIELDS). Entries already logged lose it; an entry that only changed the mode is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_audit_logs')) return;

        DB::table('organization_audit_logs')->where('subject_type', 'user_setting')->whereNotNull('changes')->orderBy('id')
            ->select(['id', 'changes'])->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $changes = json_decode((string) $row->changes, true);
                    if (! is_array($changes) || ! array_key_exists('mode', $changes)) continue;
                    unset($changes['mode']);
                    $changes
                        ? DB::table('organization_audit_logs')->where('id', $row->id)->update(['changes' => json_encode($changes)])
                        : DB::table('organization_audit_logs')->where('id', $row->id)->delete();
                }
            });
    }

    public function down(): void
    {
        // The removed entries are not brought back
    }
};
