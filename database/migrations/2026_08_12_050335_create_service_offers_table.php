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
        Schema::create('service_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->foreignId('booker_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('booking_id')->nullable()->constrained('service_books')->onDelete('set null');
            $table->decimal('original_price', 10, 2);
            $table->decimal('offered_price', 10, 2);
            $table->decimal('discount_percent', 5, 2); // calculated: (original - offered) / original * 100
            $table->text('note')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'countered'])->default('pending');
            $table->decimal('counter_price', 10, 2)->nullable(); // owner can counter offer
            $table->text('counter_note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_offers');
    }
};
