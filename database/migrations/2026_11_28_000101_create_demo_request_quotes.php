<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Proposal terms worked out for a demo / pilot / proposal request. Each save is a new row: the history of offers and concessions. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_request_quotes')) return;

        Schema::create('demo_request_quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('demo_request_id')->index();
            $table->decimal('implementation_fee', 12, 2)->default(0);
            $table->decimal('subscription_monthly', 12, 2);
            $table->string('billing_period', 12)->default('monthly');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->unsignedTinyInteger('discount_months')->default(0);
            $table->decimal('custom_development_fee', 12, 2)->default(0);
            $table->unsignedSmallInteger('review_after_days')->default(60);
            $table->text('concession_reason')->nullable();
            $table->text('scope_notes')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_request_quotes');
    }
};
