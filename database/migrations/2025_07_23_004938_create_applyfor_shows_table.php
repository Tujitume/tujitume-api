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
        Schema::create('applyfor_shows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('business_name')->nullable();
            $table->string('business_site')->nullable();
            $table->string('s_description', 1000)->nullable();
            $table->string('y_turnover')->nullable();
            $table->string('firstN')->nullable();
            $table->string('lastN')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('home_address', 500)->nullable();
            $table->string('st_address', 500)->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->string('B_address', 500)->nullable();
            $table->string('B_st_address', 500)->nullable();
            $table->string('B_city')->nullable();
            $table->string('B_state')->nullable();
            $table->string('B_zip')->nullable();
            $table->string('B_country')->nullable();
            $table->string('B_phone')->nullable();
            $table->string('p_phone')->nullable();
            $table->string('a_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('facebook')->nullable();
            $table->string('twitter')->nullable();
            $table->string('e_firstN')->nullable();
            $table->string('e_lastN')->nullable();
            $table->string('relation')->nullable();
            $table->string('e_phone')->nullable();
            $table->string('how_u_heard')->nullable();
            $table->string('business_details')->nullable();
            $table->string('business_idea')->nullable();
            $table->string('how_long')->nullable();
            $table->string('business_partners')->nullable();
            $table->string('employees')->nullable();
            $table->string('how_m_invested')->nullable();
            $table->string('challenge')->nullable();
            $table->string('business_improved')->nullable();
            $table->string('business_suffering')->nullable();
            $table->string('business_profitable')->nullable();
            $table->string('short_term_goals')->nullable();
            $table->string('long_term_goals')->nullable();
            $table->string('image1')->nullable();
            $table->string('image2')->nullable();
            $table->string('image3')->nullable();
            $table->string('image4')->nullable();
            $table->timestamps(); // created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applyfor_shows');
    }
};
