<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(['organization_id', 'user_type_id'], 'users_organization_type_index');
        });

        Schema::table('organization_user_roles', function (Blueprint $table) {
            $table->index(
                ['organization_id', 'status', 'role_id'],
                'organization_user_roles_reviewer_lookup_index',
            );
        });

        Schema::table('round_reviewers', function (Blueprint $table) {
            $table->index('user_id', 'round_reviewers_user_index');
        });
    }

    public function down(): void
    {
        Schema::table('round_reviewers', function (Blueprint $table) {
            $table->dropIndex('round_reviewers_user_index');
        });

        Schema::table('organization_user_roles', function (Blueprint $table) {
            $table->dropIndex('organization_user_roles_reviewer_lookup_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_organization_type_index');
        });
    }
};
