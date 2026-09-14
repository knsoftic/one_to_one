<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\SyncContactsRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContactController extends Controller
{
    public function __construct(private readonly ContactService $contacts) {}

    /**
     * Saved contacts who are registered on the app.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ContactResource::collection($this->contacts->listFor($request->user()))->resolve($request),
        ]);
    }

    /**
     * Match phone-book entries against registered users and save the matches.
     */
    public function sync(SyncContactsRequest $request): JsonResponse
    {
        $result = $this->contacts->sync($request->user(), $request->validated('contacts'));

        return response()->json([
            'matched' => ContactResource::collection($result['matched'])->resolve($request),
            'checked' => $result['checked'],
            // Positions of the sent entries nobody is registered with, to offer "Invite" (C8).
            'unmatched' => $result['unmatched'],
            'data' => ContactResource::collection($this->contacts->listFor($request->user()))->resolve($request),
        ]);
    }

    public function destroy(Request $request, Contact $contact): Response
    {
        abort_unless((int) $contact->user_id === (int) $request->user()->getKey(), 404);

        $contact->delete();

        return response()->noContent();
    }
}
