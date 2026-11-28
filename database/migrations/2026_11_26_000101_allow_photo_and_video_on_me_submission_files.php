<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M&E submission files: photo and video become their own categories.
 * Old 'photo_video' rows are left as they are (read as the legacy combined category), so nothing is rewritten.
 */
return new class extends Migration
{
    private const NEW = ['document', 'photo', 'video', 'photo_video', 'beneficiary_list', 'other'];
    private const OLD = ['document', 'photo_video', 'beneficiary_list', 'other'];

    public function up(): void
    {
        $this->allow(self::NEW);
    }

    public function down(): void
    {
        // Rolling back folds the split categories into the old combined one, so the old enum can hold them
        DB::table('m_e_submission_files')->whereIn('file_type', ['photo', 'video'])->update(['file_type' => 'photo_video']);
        $this->allow(self::OLD);
    }

    private function allow(array $values): void
    {
        $driver = DB::getDriverName();
        $list = implode(',', array_map(fn ($value) => "'{$value}'", $values));

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE m_e_submission_files MODIFY file_type ENUM({$list}) NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE m_e_submission_files DROP CONSTRAINT IF EXISTS m_e_submission_files_file_type_check');
            DB::statement("ALTER TABLE m_e_submission_files ADD CONSTRAINT m_e_submission_files_file_type_check CHECK (file_type IN ({$list}))");
        } elseif ($driver === 'sqlite') {
            // SQLite keeps the enum as a CHECK constraint, which only a table rebuild can change
            DB::statement('PRAGMA foreign_keys = OFF');
            Schema::create('m_e_submission_files_rebuild', function (Blueprint $table) use ($values) {
                $table->id();
                $table->foreignId('submission_id')->constrained('m_e_submissions')->onDelete('cascade');
                $table->enum('file_type', $values);
                $table->string('file_path');
                $table->string('original_filename')->nullable();
                $table->bigInteger('file_size')->nullable();
                $table->string('mime_type')->nullable();
                $table->timestamps();
            });
            $columns = 'id, submission_id, file_type, file_path, original_filename, file_size, mime_type, created_at, updated_at';
            DB::statement("INSERT INTO m_e_submission_files_rebuild ({$columns}) SELECT {$columns} FROM m_e_submission_files");
            Schema::drop('m_e_submission_files');
            Schema::rename('m_e_submission_files_rebuild', 'm_e_submission_files');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
