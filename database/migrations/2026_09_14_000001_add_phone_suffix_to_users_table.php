<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexed trailing digits of the phone number, used to match phone-book
     * contacts ("0300 1234567") against stored numbers ("+923001234567").
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_suffix', 12)->nullable()->after('phone')->index();
        });

        DB::table('users')->select(['id', 'phone'])->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['phone_suffix' => Phone::suffix((string) $user->phone)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phone_suffix']);
            $table->dropColumn('phone_suffix');
        });
    }
};
