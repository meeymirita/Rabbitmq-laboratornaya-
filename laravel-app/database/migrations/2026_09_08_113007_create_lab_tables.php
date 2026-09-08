<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->string('name');
            $t->unsignedInteger('stock');
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id');
            $t->string('status')->default('pending');      // pending|reserved|paid|cancelled|completed
            $t->unsignedTinyInteger('priority')->default(0);
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->unsignedInteger('quantity');
        });
        Schema::create('outbox_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();                     // = message_id в RabbitMQ
            $t->string('routing_key');
            $t->jsonb('payload');
            $t->unsignedTinyInteger('priority')->default(0);
            $t->string('status')->default('pending');      // pending|sent
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        Schema::create('processed_messages', function (Blueprint $t) {
            $t->id(); $t->uuid('message_id');
            $t->string('consumer');
            $t->timestamp('processed_at');
            $t->unique(['message_id', 'consumer']);        // ключ идемпотентности
        });
        Schema::create('order_events', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id');
            $t->string('event_type');
            $t->jsonb('payload');
            $t->timestamp('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('processed_messages');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
    }
};
