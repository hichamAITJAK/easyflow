<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Creatives module: product briefs, the content requests that move
 * through the review queue, and their line items. Editor pay is NOT a
 * new table — a validated request writes a row into the existing
 * commission_ledger_entries (entry_type 'creative'), which only gains a
 * nullable back-reference here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 10)->default('single'); // single, pack
            $table->json('links'); // 1 URL for single, 1..n for pack
            $table->text('description')->nullable();
            $table->string('status', 10)->default('testing'); // testing, active, inactive
            $table->unsignedInteger('works_count')->default(0); // validated creatives, denormalized
            $table->timestamp('last_push_at')->nullable(); // last validated push
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('creative_product_editor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('creative_product_id')->constrained('creative_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unique(['creative_product_id', 'user_id']);
        });

        Schema::create('content_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('creative_product_id')->constrained('creative_products')->cascadeOnDelete();
            // Kept after the editor's account is deleted so history and
            // pay rows still read who did the work.
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origin', 10)->default('admin'); // admin (requested) · editor (daily self-push)
            $table->string('status', 10)->default('sent'); // sent, returned, edits, validated
            $table->unsignedSmallInteger('rev')->default(1);
            $table->text('admin_note')->nullable();
            $table->string('drive_url', 2048)->nullable();
            $table->text('editor_note')->nullable();
            $table->json('direction_points'); // ordered strings from the admin's edit rounds
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('edits_requested_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['editor_id', 'status']);
            $table->index(['creative_product_id', 'validated_at']);
        });

        Schema::create('content_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_request_id')->constrained('content_requests')->cascadeOnDelete();
            $table->string('type', 10); // video, static
            $table->unsignedSmallInteger('count');
            $table->json('directions'); // index = creative number; empty entry = free style
        });

        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            $table->foreignId('content_request_id')->nullable()->after('performance_target_id')
                ->constrained('content_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_request_id');
        });

        Schema::dropIfExists('content_request_items');
        Schema::dropIfExists('content_requests');
        Schema::dropIfExists('creative_product_editor');
        Schema::dropIfExists('creative_products');
    }
};
