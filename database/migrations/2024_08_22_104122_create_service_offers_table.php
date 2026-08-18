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
            $table->foreignId('booking_id')->nullable()->constrained('service_bookings')->onDelete('set null');
            $table->decimal('original_price', 10, 2);
            $table->decimal('offered_price', 10, 2);
            $table->decimal('discount_percent', 5, 2); // calculated: (original - offered) / original * 100
            $table->text('note')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'countered'])->default('pending');
            $table->decimal('counter_price', 10, 2)->nullable(); // owner can counter offer
            $table->text('counter_note')->nullable();
            $table->timestamps();
        });

        Schema::table('service_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->nullable();
            $table->decimal('agreed_price', 10, 2)->nullable();
            $table->boolean('is_offer_booking')->default(false);
            $table->enum('delivery_status', ['pending', 'delivered', 'accepted', 'rejected'])->nullable();
            $table->text('delivery_note')->nullable();
            $table->text('rejection_note')->nullable();

            $table->foreign('offer_id')->references('id')->on('service_offers')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            $table->dropForeign(['offer_id']);
            $table->dropColumn([
                'offer_id',
                'agreed_price',
                'is_offer_booking',
                'delivery_status',
                'delivery_note',
                'rejection_note',
            ]);
        });

        Schema::dropIfExists('service_offers');
    }
};
