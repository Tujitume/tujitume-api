<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An organization's messages are stored as coming from the organization owner (from_id), so
     * the person who actually wrote one is recorded here.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('sent_by_id')->nullable()->after('from_id');
            $table->foreign('sent_by_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['sent_by_id']);
            $table->dropColumn('sent_by_id');
        });
    }
};
