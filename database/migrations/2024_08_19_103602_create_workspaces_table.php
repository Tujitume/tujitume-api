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
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');

            $table->string('name');
            $table->string('slug')->unique();               // agrisoko
            $table->string('subdomain')->unique()->nullable(); // agrisoko.tujitume.com
            $table->string('custom_domain')->nullable();
            $table->enum('domain_status', ['pending', 'active', 'failed'])->default('pending');
            $table->enum('workspace_status', [
                'pending_verification',
                'active',
                'suspended'
            ])->default('pending_verification');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
