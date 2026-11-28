<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A service provider shares one of their services with someone they could work for: a business (listing), a program
// or a capital offer. The owner of that business / program / offer accepts (then books the service) or declines.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_proposals')) {
            return;
        }
        Schema::create('service_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 20); // listing | program | capital_offer
            $table->unsignedBigInteger('target_id');
            $table->string('target_name')->nullable(); // kept so the request still reads well if the target is renamed or removed
            $table->text('message');
            $table->decimal('proposed_price', 14, 2)->nullable();
            $table->string('status', 20)->default('pending'); // pending | accepted | declined | withdrawn
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'status']);
            $table->index(['provider_id', 'status']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_proposals');
    }
};
