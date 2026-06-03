<?php

namespace App\Http\Controllers\Ai;

use App\Models\User;
use App\Models\WarRoomSession;
use Illuminate\Http\Request;

class WarRoomShareController
{
    public function index(Request $request, string $id)
    {
        $session = WarRoomSession::where('user_id', auth()->id())
            ->findOrFail($id);

        return response()->json([
            'viewers' => $session->viewers()->get(['users.id', 'users.name', 'users.email']),
        ]);
    }

    public function share(Request $request, string $id)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $session = WarRoomSession::where('user_id', auth()->id())
            ->findOrFail($id);

        $user = User::where('email', $request->email)->first();

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot share with yourself.'], 422);
        }

        $session->addViewer($user->id);

        return response()->json([
            'message' => 'Session shared with '.$user->name,
            'viewer' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function unshare(Request $request, string $id)
    {
        $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
        ]);

        $session = WarRoomSession::where('user_id', auth()->id())
            ->findOrFail($id);

        $session->removeViewer($request->user_id);

        return response()->json(['message' => 'Viewer removed.']);
    }
}
