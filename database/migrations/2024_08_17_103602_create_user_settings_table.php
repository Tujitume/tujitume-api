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
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->enum('theme', ['default', 'ocean', 'forest', 'sunset', 'minimal'])->default('default');
            $table->enum('mode', ['light', 'dark', 'system'])->default('system');
            $table->string('accent_color', 20)->default('#14532d'); // hex color
            $table->string('logo', 300)->nullable();           // profile/org logo
            $table->string('bg_color', 20)->nullable();
            $table->string('font_weight', 20)->default('light'); // bold, medium, large
            $table->enum('subscription_status', ['active', 'inactive'])->default('inactive');
            $table->enum('profile_visibility', ['public', 'private'])->default('public');
            $table->string('language', 10)->default('en');
            $table->string('currency', 10)->default('USD');
            $table->string('timezone', 10)->default('UTC');

            $table->string('date_format', 20)->default('DD/MM/YYYY');
            $table->json('supported_currencies')->nullable();
            $table->json('supported_languages')->nullable();

            $table->boolean('email_notifications')->default(true);
            $table->boolean('push_notifications')->default(true);
            $table->json('custom')->nullable(); // any extra settings frontend needs
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
