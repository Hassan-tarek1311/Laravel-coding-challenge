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
        Schema::create('webhook_idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->timestamp('processed_at');
            $table->string('payload_hash');
            $table->string('result_state');
            $table->timestamps();
            
            $table->index('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_idempotency');
    }
};
