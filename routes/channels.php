<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;


/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// A person's messages: new message, the other person typing, the other person read them. Only that person (or a capital
// editor/admin who shares the owner's inbox) may listen.
Broadcast::channel('chat.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId
        || \App\Http\Controllers\Misc\MessageController::inboxOwnerId($user) === (int) $userId;
});

// ISSUE-42: a milestone's step Conversation, live: the applicant and the program's owner team (whoever may open it)
Broadcast::channel('step-agreement.{milestoneId}', function ($user, $milestoneId) {
    $milestone = \App\Models\Programs\ProgramMilestone::find($milestoneId);
    $application = $milestone?->application;

    return $application && app(\App\Service\Program\ProgramAccess::class)->canViewApplication($user, $application);
});
