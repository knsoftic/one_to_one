<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Models\Status;
use App\Models\StatusView;
use App\Models\User;
use App\Rules\SingleEmoji;
use App\Services\ContactService;
use App\Services\StatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 5 — Status (stories).
 */
class StatusController extends Controller
{
    public function __construct(
        private readonly StatusService $statuses,
        private readonly ContactService $contacts,
    ) {}

    /** My updates and the updates of people who share theirs with me. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $feed = $this->statuses->feed($user);
        $people = collect($feed['updates'])->pluck('user');
        $saved = $this->contacts->savedNames($user, $people->pluck('id')->all());

        return response()->json([
            'mine' => $feed['mine']->map(fn (Status $status) => $this->statuses->payload($status, $user))->values(),
            'privacy' => $this->statuses->privacyFor($user)['mode'],
            'updates' => collect($feed['updates'])->map(fn (array $entry) => [
                'user' => (new UserResource($entry['user']))->resolve($request) + ['saved_name' => $saved[$entry['user']->id] ?? null],
                'statuses' => $entry['statuses']->map(fn (Status $status) => $this->statuses->payload($status, $user))->values(),
                'latest_at' => $entry['latest_at']?->toIso8601String(),
                'viewed' => $entry['viewed'],
                'muted' => $entry['muted'],
            ])->values(),
        ]);
    }

    /** S1: a text update, or a photo / video with a caption. */
    public function store(Request $request): JsonResponse
    {
        $uploads = config('chat.uploads');
        $type = $this->typeOf($request);
        $rules = [
            'text' => ['nullable', 'string', 'max:'.StatusService::MAX_TEXT, 'required_without:attachment'],
            'background' => ['nullable', Rule::in(Status::BACKGROUNDS)],
            'font' => ['nullable', 'integer', 'min:0', 'max:'.(Status::FONTS - 1)],
            'caption' => ['nullable', 'string', 'max:'.StatusService::MAX_TEXT],
            'attachment' => ['nullable', 'file'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:'.((int) config('chat.statuses.max_video_seconds', 60) + 1)],
            'thumbnail' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$uploads['video']['thumbnail_max_kb'], 'dimensions:max_width=4096,max_height=4096'],
        ];

        if ($type === Status::TYPE_VIDEO) {
            array_push($rules['attachment'], 'mimetypes:'.implode(',', $uploads['video']['mimetypes']), 'extensions:'.implode(',', $uploads['video']['extensions']), 'max:'.$uploads['video']['max_kb']);
        } elseif ($request->hasFile('attachment')) {
            $max = $uploads['image']['max_dimension'];
            array_push($rules['attachment'], 'image', 'mimes:jpg,jpeg,png', 'extensions:jpg,jpeg,png', 'max:'.$uploads['image']['max_kb'], "dimensions:max_width={$max},max_height={$max}");
        }

        $validated = $request->validate($rules, [
            'text.required_without' => 'Type something or choose a photo or video.',
            'attachment.mimes' => 'Only JPG and PNG photos or MP4, WEBM, MOV and 3GP videos can be shared.',
            'attachment.extensions' => 'Only JPG and PNG photos or MP4, WEBM, MOV and 3GP videos can be shared.',
            'attachment.mimetypes' => 'Only JPG and PNG photos or MP4, WEBM, MOV and 3GP videos can be shared.',
            'duration.max' => 'Status videos can be up to '.(int) config('chat.statuses.max_video_seconds', 60).' seconds long.',
        ]);

        $user = $request->user();
        $status = $type === Status::TYPE_TEXT
            ? $this->statuses->createText($user, (string) $validated['text'], $validated['background'] ?? null, (int) ($validated['font'] ?? 0))
            : $this->statuses->createMedia($user, $request->file('attachment'), $type, $validated['caption'] ?? null, $request->file('thumbnail'), isset($validated['duration']) ? (float) $validated['duration'] : null);

        return response()->json($this->statuses->payload($status, $user), 201);
    }

    public function destroy(Request $request, Status $status): JsonResponse
    {
        $this->statuses->delete($status, $request->user());

        return response()->json(['id' => $status->id, 'deleted' => true]);
    }

    /** The photo or video of an update, for the people who may see it. */
    public function media(Request $request, Status $status): BinaryFileResponse
    {
        abort_unless($this->statuses->canView($status, $request->user()), 404);

        $variant = $request->query('variant') === 'thumbnail' ? 'thumbnail' : 'original';
        $path = $this->statuses->mediaPath($status, $variant) ?? ($variant === 'thumbnail' ? $this->statuses->mediaPath($status) : null);
        abort_if($path === null, 404);

        $mime = str_ends_with($path, '_thumb.webp') ? 'image/webp' : ($status->attachment_mime ?: 'application/octet-stream');

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
        ])->setPrivate();
    }

    /** S2: I saw this update. */
    public function view(Request $request, Status $status): JsonResponse
    {
        $this->statuses->markViewed($status, $request->user());

        return response()->json(['id' => $status->id, 'viewed' => true]);
    }

    /** S2: who saw my update and when. */
    public function viewers(Request $request, Status $status): JsonResponse
    {
        $user = $request->user();
        $views = $this->statuses->viewers($status, $user);
        $saved = $this->contacts->savedNames($user, $views->pluck('user_id')->all());

        return response()->json([
            'data' => $views->map(fn (StatusView $view) => [
                'user' => (new UserResource($view->user))->resolve($request) + ['saved_name' => $saved[$view->user_id] ?? null],
                'viewed_at' => $view->viewed_at?->toIso8601String(),
                'reaction' => $view->reaction,
            ])->values(),
        ]);
    }

    /** S3: who sees my updates. */
    public function privacy(Request $request): JsonResponse
    {
        return response()->json($this->statuses->privacyFor($request->user()));
    }

    public function updatePrivacy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(Status::PRIVACY_MODES)],
            'except_ids' => ['sometimes', 'array', 'max:5000'],
            'except_ids.*' => ['integer', 'distinct'],
            'only_ids' => ['sometimes', 'array', 'max:5000'],
            'only_ids.*' => ['integer', 'distinct'],
        ]);

        return response()->json($this->statuses->updatePrivacy(
            $request->user(),
            $validated['mode'],
            array_key_exists('except_ids', $validated) ? $validated['except_ids'] : null,
            array_key_exists('only_ids', $validated) ? $validated['only_ids'] : null,
        ));
    }

    /** S4: reply to an update in the one-to-one chat. */
    public function reply(Request $request, Status $status): JsonResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'max:'.config('chat.max_message_length')]]);
        $message = $this->statuses->reply($status, $request->user(), $validated['message']);

        return response()->json((new MessageResource($message))->resolve($request), 201);
    }

    /** S4: react to an update with an emoji. */
    public function react(Request $request, Status $status): JsonResponse
    {
        $validated = $request->validate(['emoji' => ['required', 'string', new SingleEmoji]]);
        $message = $this->statuses->react($status, $request->user(), $validated['emoji']);

        return response()->json([
            'reaction' => $validated['emoji'],
            'message' => $message ? (new MessageResource($message))->resolve($request) : null,
        ]);
    }

    /** S5: move someone's updates down to "Muted updates" (or back). */
    public function mute(Request $request, User $user): JsonResponse
    {
        $muted = $request->isMethod('post');
        $this->statuses->mute($request->user(), $user, $muted);

        return response()->json(['user_id' => $user->id, 'muted' => $muted]);
    }

    private function typeOf(Request $request): string
    {
        if (! $request->hasFile('attachment')) {
            return Status::TYPE_TEXT;
        }

        $extension = strtolower($request->file('attachment')->getClientOriginalExtension());

        return in_array($extension, config('chat.uploads.video.extensions'), true) ? Status::TYPE_VIDEO : Status::TYPE_IMAGE;
    }
}
