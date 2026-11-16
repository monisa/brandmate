<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When we told someone who signed up but never subscribed that one payment unlocks everything. Once per account.
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('signup_nudged_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('signup_nudged_at'));
    }
};
