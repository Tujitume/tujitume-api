<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_agreement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')
                ->constrained('milestone_pre_agreements')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_comment_id');
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['agreement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_agreement_reads');
    }
};
