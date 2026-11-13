<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'name']);
        });

        Schema::create('shortlist_influencers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shortlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_profile_id')->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['shortlist_id', 'social_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortlist_influencers');
        Schema::dropIfExists('shortlists');
    }
};
