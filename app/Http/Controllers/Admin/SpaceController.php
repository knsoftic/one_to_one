<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Status;
use App\Services\AdminAuditService;
use App\Services\AdminContentService;
use App\Services\ChannelService;
use App\Services\CommunityService;
use App\Services\GroupService;
use App\Services\StatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin panel: groups, channels, communities and status updates — see them and delete them.
 */
class SpaceController extends Controller
{
    public function __construct(
        private readonly AdminContentService $content,
        private readonly AdminAuditService $audit,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Groups */
    /* ------------------------------------------------------------------ */

    public function groups(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', Rule::in(['active', 'deleted', 'all'])]]);

        return view('admin.groups.index', [
            'groups' => $this->content->groups($filters['q'] ?? null, $filters['state'] ?? 'active'),
            'filters' => $filters,
        ]);
    }

    public function group(Conversation $conversation): View
    {
        abort_unless($conversation->isGroup() && ! $conversation->is_announcement, 404);
        $conversation->load(['creator', 'community'])->loadCount(['messages', 'activeMembers']);

        return view('admin.groups.show', [
            'group' => $conversation,
            'members' => $this->content->members($conversation),
        ]);
    }

    public function destroyGroup(Request $request, Conversation $conversation, GroupService $groups): RedirectResponse
    {
        abort_unless($conversation->isGroup() && ! $conversation->is_announcement && $conversation->ended_at === null, 404);

        $groups->endByModerator($conversation, $request->user());
        $this->audit->record($request->user(), 'group.deleted', $conversation, "Deleted the group \"{$conversation->name}\"");

        return redirect()->route('admin.groups')->with('status', "The group \"{$conversation->name}\" was deleted for everyone.");
    }

    /* ------------------------------------------------------------------ */
    /* Channels */
    /* ------------------------------------------------------------------ */

    public function channels(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return view('admin.channels.index', ['channels' => $this->content->channels($filters['q'] ?? null), 'filters' => $filters]);
    }

    public function channel(Conversation $conversation): View
    {
        abort_unless($conversation->isChannel(), 404);
        $conversation->load('creator')->loadCount(['messages', 'activeMembers']);

        return view('admin.channels.show', [
            'channel' => $conversation,
            'admins' => $conversation->members()->with('user')->whereNull('left_at')->where('role', 'admin')->get(),
        ]);
    }

    public function destroyChannel(Request $request, Conversation $conversation, ChannelService $channels): RedirectResponse
    {
        abort_unless($conversation->isChannel(), 404);
        $name = $conversation->name;

        $channels->destroy($conversation);
        $this->audit->record($request->user(), 'channel.deleted', null, "Deleted the channel \"{$name}\"", ['channel_id' => $conversation->getKey()]);

        return redirect()->route('admin.channels')->with('status', "The channel \"{$name}\" was deleted.");
    }

    /* ------------------------------------------------------------------ */
    /* Communities */
    /* ------------------------------------------------------------------ */

    public function communities(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return view('admin.communities.index', ['communities' => $this->content->communities($filters['q'] ?? null), 'filters' => $filters]);
    }

    public function community(Community $community): View
    {
        $community->load('creator');
        $announcement = $community->announcement()->withCount(['activeMembers', 'messages'])->first();

        return view('admin.communities.show', [
            'community' => $community,
            'announcement' => $announcement,
            'groups' => $community->groups()->withCount('activeMembers')->orderBy('name')->get(),
            'members' => $announcement ? $this->content->members($announcement) : collect(),
        ]);
    }

    public function destroyCommunity(Request $request, Community $community, CommunityService $communities): RedirectResponse
    {
        $name = $community->name;

        $communities->deleteByModerator($community, $request->user());
        $this->audit->record($request->user(), 'community.deleted', null, "Deleted the community \"{$name}\"", ['community_id' => $community->getKey()]);

        return redirect()->route('admin.communities')->with('status', "The community \"{$name}\" was deleted. Its groups stay as ordinary groups.");
    }

    /* ------------------------------------------------------------------ */
    /* Status updates */
    /* ------------------------------------------------------------------ */

    public function statuses(Request $request): View
    {
        $filters = $request->validate(['user' => ['nullable', 'integer']]);

        return view('admin.statuses.index', ['statuses' => $this->content->statuses($filters['user'] ?? null), 'filters' => $filters]);
    }

    public function statusMedia(Request $request, Status $status, StatusService $statuses): BinaryFileResponse
    {
        $variant = $request->query('variant') === 'thumbnail' ? 'thumbnail' : 'original';
        $path = $statuses->mediaPath($status, $variant) ?? ($variant === 'thumbnail' ? $statuses->mediaPath($status) : null);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => str_ends_with($path, '_thumb.webp') ? 'image/webp' : ($status->attachment_mime ?: 'application/octet-stream'),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
        ])->setPrivate();
    }

    public function destroyStatus(Request $request, Status $status, StatusService $statuses): RedirectResponse
    {
        $status->loadMissing('user');
        $owner = $status->user?->name ?? 'a deleted account';

        $statuses->remove($status);
        $this->audit->record($request->user(), 'status.deleted', null, "Deleted a status update from {$owner}", ['status_id' => $status->getKey(), 'user_id' => $status->user_id]);

        return back()->with('status', 'The status update was deleted.');
    }
}
