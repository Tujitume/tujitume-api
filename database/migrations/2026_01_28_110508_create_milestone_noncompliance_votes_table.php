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
        Schema::create('milestone_noncompliance_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('non_compliance_id')->constrained('milestone_non_compliances')->cascadeOnDelete();
            $table->foreignId('investor_id')->constrained('users');

            $table->enum('vote', ['continue', 'freeze', 'dispute']);
            $table->decimal('weight', 10, 2); // investment-based

            $table->timestamps();

            $table->unique(
                ['non_compliance_id', 'investor_id'],
                'nc_votes_nc_investor_unique'
            );
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_noncompliance_votes');
    }
};
