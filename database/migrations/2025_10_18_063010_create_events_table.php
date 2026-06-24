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
        Schema::create('events', function (Blueprint $table) {

                $table->id();
                $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

                $table->string('event_name');
                $table->string('event_type');
                $table->string('sector');
                $table->dateTime('start_date');
                $table->dateTime('end_date');
                $table->string('timezone');
                $table->string('location_type');

                // In-person fields
                $table->string('country')->nullable();
                $table->string('city')->nullable();
                $table->string('venue')->nullable();
                $table->string('address')->nullable();

                // Virtual fields
                $table->string('virtual_url')->nullable();

                $table->text('description');
                $table->string('cost_type');
                $table->string('currency')->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->string('ticket_link')->nullable();
                $table->string('cover_image'); // store filename or URL
                $table->string('brochure')->nullable(); // store filename or URL

                $table->json('tags')->nullable();
                $table->tinyInteger('active')->nullable()->default(1);
                $table->timestamps(); // created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
