<?php

namespace Tests\Unit;

use App\Models\Message;
use PHPUnit\Framework\TestCase;

/**
 * M4 — previews and notifications show text without formatting markers.
 */
class MessagePreviewTest extends TestCase
{
    public function test_formatting_markers_are_removed_from_previews(): void
    {
        $message = new Message(['message' => "*Meeting* at _5pm_ ~today~\n```room 4```", 'message_type' => Message::TYPE_TEXT]);

        $this->assertSame('Meeting at 5pm today room 4', $message->preview());
    }

    public function test_markers_inside_words_are_kept(): void
    {
        $this->assertSame('snake_case_name 2*3*4', Message::stripFormatting('snake_case_name 2*3*4'));
    }
}
