<?php

use App\Models\Communication\Notifications;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// C6: notices already sent to service providers with Business Coach links now open the provider portal
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->join('users', 'users.id', '=', 'notifications.receiver_id')
            ->where('users.user_type_id', 3)->where('notifications.link', 'like', 'dashboard.reviewerGrantOrg%')
            ->select('notifications.id', 'notifications.link')->orderBy('notifications.id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('notifications')->where('id', $row->id)->update(['link' => Notifications::providerLink($row->link)]);
                }
            });
    }

    public function down(): void
    {
    }
};
