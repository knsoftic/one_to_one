<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * "Save contact": a contact card as a vCard file the phone can import.
 */
class ContactCardController extends Controller
{
    public function show(Message $message): Response
    {
        Gate::authorize('view', $message);
        abort_unless($message->message_type === Message::TYPE_CONTACT, 404);

        $meta = $message->attachment_meta ?? [];
        $name = (string) ($meta['name'] ?? 'Contact');

        $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'FN:'.self::escape($name), 'N:'.self::escape($name).';;;;'];
        foreach ($meta['phones'] ?? [] as $phone) {
            $lines[] = 'TEL;TYPE=CELL:'.self::escape((string) $phone);
        }
        $lines[] = 'END:VCARD';

        $filename = (Str::slug($name) ?: 'contact').'.vcf';

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** vCard text escaping (RFC 6350): backslash, comma, semicolon and line breaks. */
    private static function escape(string $value): string
    {
        return str_replace(['\\', ',', ';', "\r\n", "\n", "\r"], ['\\\\', '\\,', '\\;', '\\n', '\\n', ''], $value);
    }
}
