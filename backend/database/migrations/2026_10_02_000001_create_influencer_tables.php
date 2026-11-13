<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The creator (a person or brand). Shared by all companies: not tenant-owned.
        Schema::create('influencers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('category')->nullable()->index();
            $table->text('bio')->nullable();
            $table->string('country', 2)->nullable()->index();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('language')->nullable();
            $table->timestamps();
        });

        // One account of a creator on one platform. This is what search returns.
        // Every metric is nullable: NULL means "Data unavailable", never zero.
        Schema::create('social_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20); // instagram | youtube | facebook
            $table->string('username');
            $table->string('profile_url')->nullable();
            $table->string('avatar_url')->nullable();
            $table->boolean('is_verified')->nullable();
            $table->unsignedBigInteger('followers')->nullable();
            $table->unsignedBigInteger('following')->nullable();
            $table->decimal('engagement_rate', 5, 2)->nullable(); // percent
            $table->unsignedBigInteger('avg_likes')->nullable();
            $table->unsignedBigInteger('avg_comments')->nullable();
            $table->unsignedBigInteger('avg_views')->nullable();
            // Provenance, required by the spec for every external metric.
            $table->string('data_source', 40);  // sample | official_api | authorized_creator | derived
            $table->string('provider', 60);
            $table->string('sync_status', 20)->default('synced'); // synced | pending | failed
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'username']);
            $table->index(['platform', 'followers']);
            $table->index('followers');
            $table->index('engagement_rate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_profiles');
        Schema::dropIfExists('influencers');
    }
};
