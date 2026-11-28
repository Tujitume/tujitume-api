<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Add / Edit Service form asks for the provider's business details (legal name, registration,
 * tax PIN, address, licence). They had no columns, so create dropped them and edit failed.
 */
return new class extends Migration
{
    private array $strings = [
        'business_legal_name' => 255,
        'legal_structure' => 100,
        'registration_number' => 100,
        'registration_country' => 100,
        'tax_pin' => 100,
        'country' => 100,
        'region' => 150,
        'physical_address' => 300,
        'professional_licence' => 500,
    ];

    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            foreach ($this->strings as $column => $length) {
                if (!Schema::hasColumn('services', $column)) $table->string($column, $length)->nullable();
            }
            if (!Schema::hasColumn('services', 'operates_through_business')) $table->boolean('operates_through_business')->default(false);
            if (!Schema::hasColumn('services', 'requires_professional_licence')) $table->boolean('requires_professional_licence')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            foreach ([...array_keys($this->strings), 'operates_through_business', 'requires_professional_licence'] as $column) {
                if (Schema::hasColumn('services', $column)) $table->dropColumn($column);
            }
        });
    }
};
