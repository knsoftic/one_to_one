<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\AdminContentService;
use App\Services\AttachmentService;
use App\Services\MessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Admin panel: every chat and its messages (recorded in the audit log), message
 * search, attachments and deleting a message for everyone.
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly AdminContentService $content,
        private readonly AdminAuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(AdminContentService::CHAT_TYPES))],
            'q' => ['nullable', 'string', 'max:100'],
            'user' => ['nullable', 'integer'],
        ]);

        return view('admin.chats.index', [
            'chats' => $this->content->chats($filters),
            'filters' => $filters,
            'person' => isset($filters['user']) ? User::query()->find($filters['user']) : null,
            'content' => $this->content,
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $before = $request->integer('before') ?: null;
        $conversation->load(['userOne', 'userTwo', 'community', 'creator']);
        $page = $this->content->messages($conversation, $before);
        $title = $this->content->title($conversation);

        // Every time an administrator opens someone's chat it is written down.
        if ($before === null) {
            $this->audit->record($request->user(), 'chat.viewed', $conversation, "Opened the chat \"{$title}\"", ['type' => $this->content->typeLabel($conversation)]);
        }

        return view('admin.chats.show', [
            'chat' => $conversation,
            'title' => $title,
            'typeLabel' => $this->content->typeLabel($conversation),
            'messages' => $page['messages'],
            'older' => $page['older'],
            'members' => $conversation->hasMembers() ? $this->content->members($conversation) : collect(),
            'broadcastRecipients' => $conversation->isBroadcast() ? $conversation->broadcastRecipients()->get() : collect(),
        ]);
    }

    public function search(Request $request): View
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
        $results = mb_strlen($q) >= 2 ? $this->content->searchMessages($q) : null;

        if ($results !== null && ! $request->has('page')) {
            $this->audit->record($request->user(), 'messages.searched', null, "Searched all messages for \"{$q}\"", ['results' => $results->total()]);
        }

        return view('admin.messages.index', ['q' => $q, 'results' => $results, 'content' => $this->content]);
    }

    public function attachment(Request $request, Message $message, AttachmentService $attachments): BinaryFileResponse
    {
        $variant = $request->query('variant') === 'thumbnail' ? 'thumbnail' : 'original';
        $path = $attachments->path($message, $variant) ?? ($variant === 'thumbnail' ? $attachments->path($message) : null);
        abort_if($path === null, 404);

        $mime = str_ends_with($path, '_thumb.webp') && $variant === 'thumbnail' ? 'image/webp' : ($message->attachment_mime ?: 'application/octet-stream');
        $inline = ! $request->boolean('download') && (str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || $mime === 'application/pdf');
        $name = (string) ($message->attachment_name ?: 'attachment');

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $name, preg_replace('/[^\x20-\x7E]/', '_', $name)),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'".($mime === 'application/pdf' ? '' : '; sandbox'),
        ])->setPrivate();
    }

    public function destroyMessage(Request $request, Message $message, MessageService $messages): RedirectResponse
    {
        abort_if($message->deleted_for_everyone || $message->message_type === Message::TYPE_SYSTEM, 404);

        $preview = $message->preview(80);
        $sender = $message->sender?->name ?? 'a deleted account';
        $messages->deleteForEveryone($message);
        $this->audit->record($request->user(), 'message.deleted', $message, "Deleted a message from {$sender}", ['conversation_id' => $message->conversation_id, 'preview' => $preview]);

        return back()->with('status', 'The message was deleted for everyone.');
    }
}
