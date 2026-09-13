<?php

namespace App\Http\Controllers;

use App\Services\GifService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GifController extends Controller
{
    public function __construct(private readonly GifService $gifs) {}

    /**
     * Trending GIFs or search results (only when a Tenor key is configured).
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->gifs->enabled(), 404);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'pos' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            return response()->json($this->gifs->search(trim((string) ($validated['q'] ?? '')) ?: null, $validated['pos'] ?? null));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
