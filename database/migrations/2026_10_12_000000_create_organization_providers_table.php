<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Tujitume service providers an organization has added as third-party reviewers/auditors.
     * A provider's own account is not tied to any organization, so this link is what remembers who works
     * with whom. One row per organization and provider.
     */
    public function up(): void
    {
        if (Schema::hasTable('organization_providers')) {
            return;
        }

        Schema::create('organization_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_providers');
    }
};
