<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A document is accepted only when its extension is allowed AND the content
 * type detected from the file itself belongs to that extension (config
 * chat.uploads.document.types), so e.g. a web page renamed to ".txt" or a
 * program renamed to ".zip" is refused.
 */
class DocumentType implements ValidationRule
{
    public const MESSAGE = 'This file type cannot be sent. Allowed: PDF, Word, Excel, PowerPoint, OpenDocument, RTF, TXT, CSV, ZIP, RAR, 7Z, MP3 and M4A.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $types = config("chat.uploads.document.types.{$extension}");
        $detected = strtolower((string) $value->getMimeType());

        if (! is_array($types) || ! in_array($detected, array_map('strtolower', $types), true)) {
            $fail(self::MESSAGE);
        }
    }
}
