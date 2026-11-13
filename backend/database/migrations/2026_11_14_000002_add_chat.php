<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One conversation per brand company and creator. The whole brand team shares it.
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            // The newest message each side has seen, which gives the unread counts.
            $table->unsignedBigInteger('brand_last_read_id')->default(0);
            $table->unsignedBigInteger('creator_last_read_id')->default(0);
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['company_id', 'influencer_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_side', 10); // brand or creator
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
