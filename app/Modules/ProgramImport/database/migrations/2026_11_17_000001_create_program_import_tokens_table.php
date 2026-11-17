<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_import_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('user_id');
            $t->string('provider', 20)->default('google');
            $t->text('access_token');          // encrypted
            $t->text('refresh_token')->nullable(); // encrypted
            $t->timestamp('expires_at')->nullable();
            $t->json('scopes')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_import_tokens');
    }
};
