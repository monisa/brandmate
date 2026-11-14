<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // A deleted message keeps its place in the thread but loses its text. changed_at lets the
            // other side's page notice an edit or a delete without reloading the whole conversation.
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('changed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'deleted_at', 'changed_at']);
        });
    }
};
