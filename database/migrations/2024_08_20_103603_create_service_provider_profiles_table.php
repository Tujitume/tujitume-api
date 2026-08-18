<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');

            // ─── Profile ─────────────────────────────────────────────────
            $table->string('supplier_type')->nullable();     // business_service, consultant, etc
            $table->text('bio')->nullable();

            // ─── Location & Service Areas ─────────────────────────────────
            $table->string('region')->nullable();
            $table->json('service_areas')->nullable();       // ['nairobi', 'remote_online']

            // ─── Availability ─────────────────────────────────────────────
            $table->enum('work_mode', ['remote', 'onsite', 'hybrid'])->default('hybrid');
            $table->json('available_days')->nullable();      // ['monday', 'tuesday'...]
            $table->string('available_from', 10)->nullable(); // '09:00'
            $table->string('available_to', 10)->nullable();   // '17:00'
            $table->string('timezone')->nullable();

            // ─── Preferences ─────────────────────────────────────────────
            $table->string('preferred_currency', 10)->default('USD');
            $table->string('preferred_language', 10)->default('en');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_provider_profiles');
    }
};
