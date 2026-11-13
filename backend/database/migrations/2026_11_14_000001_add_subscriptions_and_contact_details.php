<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contact details that a subscription unlocks.
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 25)->nullable();
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->string('phone', 25)->nullable();
            $table->string('channel_name', 120)->nullable(); // the brand's social channel
            $table->string('industry', 80)->nullable();
        });

        // One row per subscriber: a creator (user_id) or a brand company (company_id).
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('created'); // created, active, past_due, cancelled, expired
            $table->string('gateway', 20)->default('razorpay'); // razorpay, or manual for a grant by the platform team
            $table->string('gateway_subscription_id')->nullable()->unique();
            // Amounts in paise: the monthly price, the GST on it and the total charged.
            $table->unsignedInteger('price_minor');
            $table->unsignedInteger('gst_minor');
            $table->unsignedInteger('total_minor');
            $table->string('currency', 3)->default('INR');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();
        });

        // Every successful charge, which doubles as the GST invoice.
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_payment_id')->nullable()->unique(); // makes a repeated webhook harmless
            $table->unsignedInteger('price_minor');
            $table->unsignedInteger('gst_minor');
            $table->unsignedInteger('total_minor');
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->default('paid');
            $table->timestamp('paid_at');
            $table->string('invoice_number')->nullable()->unique();
            $table->timestamps();
        });

        // Webhook deliveries already handled, so a retry changes nothing.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['phone', 'channel_name', 'industry']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('phone'));
    }
};
