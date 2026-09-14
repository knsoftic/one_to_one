<?php

namespace App\Http\Controllers;

use App\Services\LinkedDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * P10 — Linked devices: log in on a computer by scanning its QR code with your phone.
 */
class LinkedDeviceController extends Controller
{
    public function __construct(private readonly LinkedDeviceService $links) {}

    /** Login page: a new QR code. */
    public function create(Request $request): JsonResponse
    {
        return response()->json($this->links->create($request), 201);
    }

    /** Login page: approved yet? */
    public function status(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate(['secret' => ['required', 'string', 'size:40']]);

        return response()->json($this->links->poll($request, $token, $validated['secret']));
    }

    /** Phone: opened the QR link directly. */
    public function page(Request $request, string $token): View
    {
        return view('chat.index', [
            'user' => $request->user(),
            'initialConversationId' => null,
            'linkDevice' => ['token' => $token],
        ]);
    }

    /** Phone: which device is asking? */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'max:64', 'required_without:code'],
            'code' => ['nullable', 'string', 'max:20'],
        ], ['token.required_without' => 'Scan the QR code or type the code.']);

        return response()->json($this->links->describe($this->links->find($validated['token'] ?? null, $validated['code'] ?? null)));
    }

    /** Phone: yes, sign that device in. */
    public function approve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'max:64', 'required_without:code'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        $link = $this->links->find($validated['token'] ?? null, $validated['code'] ?? null);
        $this->links->approve($link, $request->user());

        return response()->json(['approved' => true] + $this->links->describe($link));
    }
}
