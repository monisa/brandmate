<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // brand: works inside a company. creator: owns one creator profile.
            $table->string('account_type', 20)->default('brand')->after('is_super_admin');
        });

        Schema::table('influencers', function (Blueprint $table) {
            // Set when the creator registered themselves. NULL for imported/sample rows.
            $table->foreignId('user_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
            // listed: brands can find it. hidden: only the creator sees it.
            $table->string('status', 20)->default('listed')->index()->after('language');
        });

        // What a creator offers and for how much, e.g. "1 Instagram Reel".
        Schema::create('creator_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor'); // paise: money is never stored as a float
            $table->string('currency', 3)->default('INR');
            $table->unsignedSmallInteger('delivery_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_packages');

        Schema::table('influencers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
