<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A program's cover image (a key on the s3 disk, served as cover_image_url) and intro video (a YouTube, Vimeo or
 * direct .mp4/.webm link), shown to applicants on the listing and the program page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'cover_image')) {
                $table->string('cover_image', 500)->nullable();
            }
            if (! Schema::hasColumn('programs', 'intro_video_url')) {
                $table->string('intro_video_url', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            foreach (['cover_image', 'intro_video_url'] as $column) {
                if (Schema::hasColumn('programs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
