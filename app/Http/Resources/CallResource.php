<?php

namespace App\Http\Resources;

use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Call
 */
class CallResource extends JsonResource
{
    /**
     * Viewer-neutral payload for broadcasts.
     */
    public static function forBroadcast(Call $call): array
    {
        $call->loadMissing(['caller', 'callee']);

        return (new self($call))->resolve(Request::create('/'));
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'type' => $this->type,
            'status' => $this->status,
            'end_reason' => $this->end_reason,
            'caller_id' => $this->caller_id,
            'callee_id' => $this->callee_id,
            // Group call this call rings into (K6): who is already talking.
            'call_room_id' => $this->call_room_id,
            'room' => $this->when($this->call_room_id !== null, fn () => [
                'id' => $this->call_room_id,
                'participants' => $this->resource->room?->participants()->joined()->with('user:id,name')->get()
                    ->map(fn ($p) => ['user_id' => $p->user_id, 'name' => $p->user?->name])->values()->all() ?? [],
            ]),
            'caller_client' => $this->caller_client,
            'callee_client' => $this->callee_client,
            'caller' => $this->whenLoaded('caller', fn () => (new UserResource($this->caller))->resolve($request)),
            'callee' => $this->whenLoaded('callee', fn () => (new UserResource($this->callee))->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
            'ringing_at' => $this->ringing_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration' => $this->duration,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
