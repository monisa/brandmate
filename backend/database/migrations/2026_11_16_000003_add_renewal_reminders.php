<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // The period end each e-mail was sent for. Paying again moves the period end, which
            // makes the next month's reminder fire again, and sending twice for one end is skipped.
            $table->timestamp('ending_reminded_for')->nullable();
            $table->timestamp('ended_notified_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['ending_reminded_for', 'ended_notified_for']);
        });
    }
};
