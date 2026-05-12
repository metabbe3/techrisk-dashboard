<?php

use App\Models\User;
use App\Models\WarRoomSession;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth']]);

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

// Authorize users to listen to their own private notification channel
Broadcast::channel('users.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

// Presence channel for online user tracking (optional)
Broadcast::channel('presence.online', function ($user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ];
});

Broadcast::channel('war-room.{sessionId}', function (User $user, string $sessionId) {
    $session = WarRoomSession::find($sessionId);

    return $session && (int) $session->user_id === (int) $user->id;
});
