<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sink')->create('messages', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('app')->index();
            $table->string('stream')->nullable();
            $table->string('subject')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('attachment_count')->default(0);
            $table->unsignedInteger('link_count')->default(0);
            $table->string('truncation');
            $table->string('raw_object_key');
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->index(['app', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('sink')->dropIfExists('messages');
    }
};
