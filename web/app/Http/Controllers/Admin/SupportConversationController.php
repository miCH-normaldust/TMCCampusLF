<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\TextMasker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SupportConversationController extends Controller
{
    public function index(Request $request): View
    {
        $conversations = Conversation::with([
            'supportUser:id,first_name,last_name,email',
            'supportSighting.post:id,item_name',
            'latestMessage.sender:id,first_name,last_name,role',
        ])
            ->whereNotNull('support_user_id')
            ->latest('updated_at')
            ->get();

        $selected = $request->integer('conversation')
            ? $conversations->firstWhere('id', $request->integer('conversation'))
            : $conversations->first();
        if ($selected) {
            $selected->load([
                'supportUser:id,first_name,last_name,email',
                'supportSighting.post:id,item_name',
                'supportSighting.user:id,first_name,last_name',
            ]);
            $selected->setRelation(
                'messages',
                $selected->messages()->with('sender:id,first_name,last_name,role')
                    ->oldest()->get(),
            );
        }

        return view('admin.chats', [
            'conversations' => $conversations,
            'selected' => $selected,
        ]);
    }

    public function send(
        Request $request,
        Conversation $conversation,
        TextMasker $masker,
    ): RedirectResponse {
        abort_unless($conversation->support_user_id !== null, 404);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $message = $conversation->messages()->create([
            'sender_id' => $request->user('admin')->id,
            'body' => $masker->mask($data['body']),
        ]);
        $conversation->touch();
        $conversation->supportUser->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'admin_chat_message',
            'data' => [
                'title' => 'Message from TMC administrators',
                'body' => 'A TMC administrator replied to your message.',
                'conversation_id' => $conversation->id,
                'sighting_id' => $conversation->support_sighting_id,
            ],
        ]);

        return redirect()
            ->route('admin.chats.index', ['conversation' => $conversation->id])
            ->with('status', 'Message sent.');
    }
}
