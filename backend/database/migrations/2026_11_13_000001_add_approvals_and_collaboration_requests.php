<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Brands (companies) and creator profiles are reviewed by a super admin.
        // The default is "approved" so everything that exists today stays live;
        // new signups are set to "pending" in code.
        foreach (['companies', 'influencers'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('approval_status', 20)->default('approved')->index();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();
            });
        }

        // A brand asks a creator to work together; the creator answers.
        Schema::create('collaboration_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('creator_packages')->nullOnDelete();
            $table->string('title', 160);
            $table->text('message');
            $table->unsignedBigInteger('budget_minor')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('pending'); // pending, accepted, declined, withdrawn
            $table->text('creator_response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['influencer_id', 'status']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_requests');

        foreach (['influencers', 'companies'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('reviewed_by');
                $table->dropColumn(['approval_status', 'reviewed_at', 'review_note']);
            });
        }
    }
};
