<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Streams private attachments to conversation participants only.
 */
class AttachmentController extends Controller
{
    private const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(private readonly AttachmentService $attachments) {}

    public function show(Request $request, Message $message): BinaryFileResponse
    {
        Gate::authorize('view', $message);

        $variant = $request->query('variant') === 'thumbnail' ? 'thumbnail' : 'original';
        $path = $this->attachments->path($message, $variant)
            ?? ($variant === 'thumbnail' ? $this->attachments->path($message) : null);

        abort_if($path === null, 404);

        $isThumbnail = $variant === 'thumbnail' && str_ends_with($path, '_thumb.webp');
        $mime = $isThumbnail ? 'image/webp' : ($message->attachment_mime ?: 'application/octet-stream');
        $isAudio = str_starts_with($mime, 'audio/') || $mime === 'video/webm' || $mime === 'application/ogg';

        $disposition = ! $request->boolean('download') && (in_array($mime, self::INLINE_TYPES, true) || $isAudio)
            ? HeaderUtils::DISPOSITION_INLINE
            : HeaderUtils::DISPOSITION_ATTACHMENT;

        $name = (string) $message->attachment_name;

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $disposition,
                $name !== '' ? $name : 'attachment',
                preg_replace('/[^\x20-\x7E]/', '_', $name ?: 'attachment')
            ),
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // Never let an uploaded file run scripts in the app's origin
            // (browsers refuse to render sandboxed PDFs, so PDFs skip "sandbox").
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'"
                .($mime === 'application/pdf' ? '' : '; sandbox'),
        ]);
    }
}
