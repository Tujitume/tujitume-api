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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id(); // bigint unsigned primary key
            $table->unsignedInteger('investor_id')->nullable();
            $table->unsignedInteger('listing_id')->nullable();

            $table->string('package', 255)->nullable();
            $table->float('price')->nullable(); // updated to float

            $table->tinyInteger('active')->default(1);

            $table->timestamps(); // created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
