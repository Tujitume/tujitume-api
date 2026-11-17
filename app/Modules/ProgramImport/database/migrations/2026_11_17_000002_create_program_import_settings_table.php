<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_import_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->unique();
            $t->boolean('ai_real_samples')->default(false);   // organization consent to send real (non-contact) sample values to the AI provider
            $t->unsignedBigInteger('consented_by')->nullable();
            $t->timestamp('consented_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_import_settings');
    }
};
