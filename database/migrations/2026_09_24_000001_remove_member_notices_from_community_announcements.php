<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Members of a community no longer see each other (G10): the "joined", "added",
     * "left" and "removed" notices already posted in announcement groups are removed.
     */
    public function up(): void
    {
        $events = ['members_added', 'member_joined_link', 'member_left', 'member_removed'];

        $notices = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.is_announcement', true)
            ->where('messages.message_type', 'system')
            ->whereIn('messages.attachment_meta->event', $events)
            ->pluck('messages.id');

        foreach ($notices->chunk(500) as $ids) {
            $ids = $ids->values()->all();
            $conversationIds = DB::table('messages')->whereIn('id', $ids)->distinct()->pluck('conversation_id');

            DB::table('messages')->whereIn('reply_to_id', $ids)->update(['reply_to_id' => null]);
            DB::table('conversations')->whereIn('last_message_id', $ids)->update(['last_message_id' => null]);
            DB::table('messages')->whereIn('id', $ids)->delete();

            // Point the chat list at the newest message still there.
            foreach ($conversationIds as $conversationId) {
                DB::table('conversations')->where('id', $conversationId)->whereNull('last_message_id')
                    ->update(['last_message_id' => DB::table('messages')->where('conversation_id', $conversationId)->max('id')]);
            }
        }
    }

    public function down(): void
    {
        // Removed notices can't be brought back.
    }
};
