<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // What the money arrived for; the allocations say what it actually paid.
            $table->nullableMorphs('payable');
            $table->string('source');
            $table->string('method');
            $table->string('external_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('currency', 3)->default('EUR');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('recorded_by_id')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_id')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->morphs('payable');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
