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
        Schema::table('service_books', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->nullable()->after('business_bid_id');
            $table->decimal('agreed_price', 10, 2)->nullable()->after('offer_id');
            $table->boolean('is_offer_booking')->default(false)->after('agreed_price');
            $table->enum('delivery_status', ['pending', 'delivered', 'accepted', 'rejected'])->nullable()->after('is_offer_booking');
            $table->text('delivery_note')->nullable()->after('delivery_status');
            $table->text('rejection_note')->nullable()->after('delivery_note');

            $table->foreign('offer_id')->references('id')->on('service_offers')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('service_books', function (Blueprint $table) {
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
    }
};
