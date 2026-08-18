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
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');

            // ─── Identity ────────────────────────────────────────────────
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();      // "Mercy Advisory" or "AgriSoko"
            $table->string('email')->unique();
            $table->string('phone', 50)->nullable();
            $table->string('image', 300)->nullable();
            $table->string('gender', 50)->nullable();
            $table->string('dob', 100)->nullable();

            // ─── Auth ────────────────────────────────────────────────────
            $table->string('password')->nullable();
            $table->string('token')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('email_verified_at')->nullable();

            // ─── User Type ───────────────────────────────────────────────
            $table->unsignedTinyInteger('user_type_id')->nullable();
            // 1=business_owner, 2=investor, 3=service_provider, 4=organization, 5=admin, 6=reviewer_internal, 7=reviewer_external

            // ─── Onboarding ──────────────────────────────────────────────
            $table->unsignedTinyInteger('completed_onboarding')->default(0);

            // ─── Location (basic, shared) ────────────────────────────────
            $table->string('country', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('website')->nullable();

            // ─── Payment (shared across types) ───────────────────────────
            $table->string('lipr_wallet_account', 200)->nullable();
            $table->string('stripe_connect_id')->nullable();        // Stripe Connect
            $table->string('stripe_customer_id')->nullable();

            $table->unsignedBigInteger('organization_id')->nullable(); // ← no constrained()

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
