<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One emoji (including skin tones, flags and ZWJ sequences such as 👨‍👩‍👧), nothing else.
 */
class SingleEmoji implements ValidationRule
{
    /** Pictographic code points (emoji blocks and the older symbol ranges used as emoji). */
    private const PICTOGRAPH = '\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{21AA}\x{231A}-\x{23FF}\x{24C2}'
        .'\x{25AA}-\x{27BF}\x{2934}\x{2935}\x{2B05}-\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1FAFF}';

    /** Joiners, variation selectors, skin tones, keycap and tag characters. */
    private const MODIFIERS = '\x{200D}\x{FE0E}\x{FE0F}\x{20E3}\x{1F3FB}-\x{1F3FF}\x{E0020}-\x{E007F}';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valid = is_string($value)
            && mb_strlen($value) <= 16
            && preg_match('/^['.self::PICTOGRAPH.self::MODIFIERS.']+$/u', $value) === 1
            && preg_match('/['.self::PICTOGRAPH.']/u', $value) === 1;

        if (! $valid) {
            $fail('Choose an emoji.');
        }
    }
}
