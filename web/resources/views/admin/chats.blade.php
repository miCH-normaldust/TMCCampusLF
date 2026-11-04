@extends('admin.layout')

@section('title', 'Chats')

@section('content')
    @php($adminId = auth('admin')->id())

    <div class="page-heading">
        <div>
            <p class="eyebrow">MEMBER SUPPORT</p>
            <h1>Chats</h1>
            <p class="muted">Reply to members who contacted TMC about lost-item evidence.</p>
        </div>
    </div>

    <div class="messenger">
        <aside class="messenger-sidebar" aria-label="Conversations">
            <div class="messenger-sidebar-head">
                <strong>Conversations</strong>
                <span class="pill">{{ $conversations->count() }}</span>
            </div>
            <div class="conversation-list">
                @forelse ($conversations as $conversation)
                    @php($latest = $conversation->latestMessage)
                    @php($isActive = $selected && $selected->id === $conversation->id)
                    <a href="{{ route('admin.chats.index', ['conversation' => $conversation->id]) }}"
                       class="conversation-item {{ $isActive ? 'active' : '' }}"
                       @if ($isActive) aria-current="true" @endif>
                        <span class="conversation-avatar" aria-hidden="true">{{ strtoupper(substr($conversation->supportUser->first_name ?? '?', 0, 1)) }}</span>
                        <span class="conversation-body">
                            <span class="conversation-top">
                                <strong>{{ $conversation->supportUser->first_name }} {{ $conversation->supportUser->last_name }}</strong>
                                <small>{{ $conversation->updated_at?->diffForHumans(short: true) }}</small>
                            </span>
                            <span class="conversation-subject">{{ $conversation->supportSighting?->post?->item_name ?? 'Evidence review' }}</span>
                            <span class="conversation-preview">{{ $latest?->body ? \Illuminate\Support\Str::limit($latest->body, 48) : 'No messages yet' }}</span>
                        </span>
                    </a>
                @empty
                    <div class="messenger-empty">No member support chats yet.</div>
                @endforelse
            </div>
        </aside>

        <section class="messenger-main" aria-live="polite">
            @if ($selected)
                <header class="messenger-thread-head">
                    <div>
                        <h2>{{ $selected->supportUser->first_name }} {{ $selected->supportUser->last_name }}</h2>
                        <p class="muted">{{ $selected->supportSighting?->post?->item_name ?? 'Evidence review' }}</p>
                    </div>
                    @if ($selected->supportUser->email)
                        <a class="button secondary" href="mailto:{{ $selected->supportUser->email }}">Email member</a>
                    @endif
                </header>

                @if ($selected->supportSighting)
                    <div class="messenger-evidence">
                        <strong>Submitted evidence</strong>
                        <p>{{ $selected->supportSighting->evidence_text }}</p>
                        @if ($selected->supportSighting->evidence_image)
                            <img src="{{ \App\Support\PublicMediaUrl::forPath($selected->supportSighting->evidence_image) }}"
                                 alt="Submitted lost-item evidence">
                        @endif
                    </div>
                @endif

                <div class="messenger-thread">
                    @forelse ($selected->messages as $message)
                        @php($mine = $message->sender_id === $adminId)
                        <div class="message-row {{ $mine ? 'sent' : 'received' }}">
                            @unless ($mine)
                                <span class="message-avatar" aria-hidden="true">{{ strtoupper(substr($message->sender->first_name ?? '?', 0, 1)) }}</span>
                            @endunless
                            <div class="message-bubble">
                                <div class="message-meta">
                                    <strong>{{ $mine ? 'You' : trim(($message->sender->first_name ?? '').' '.($message->sender->last_name ?? '')) }}</strong>
                                    <small>{{ $message->created_at->format('M j, g:i A') }}</small>
                                </div>
                                <p>{{ $message->body }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="messenger-empty">No messages in this conversation yet.</div>
                    @endforelse
                </div>

                <form class="messenger-composer" method="post" action="{{ route('admin.chats.send', $selected) }}">
                    @csrf
                    <label class="sr-only" for="reply">Reply</label>
                    <textarea id="reply" name="body" rows="2" maxlength="2000" required placeholder="Write a reply…"></textarea>
                    <button class="button" type="submit">Send</button>
                </form>
            @else
                <div class="messenger-empty">Select a conversation to view messages.</div>
            @endif
        </section>
    </div>
@endsection
