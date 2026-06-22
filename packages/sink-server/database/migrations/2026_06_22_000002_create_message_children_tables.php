<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sink')->create('message_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->index()->constrained('messages')->cascadeOnDelete();
            $table->string('kind');
            $table->string('address');
            $table->string('name')->nullable();
        });

        Schema::connection('sink')->create('message_headers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->index()->constrained('messages')->cascadeOnDelete();
            $table->string('name');
            $table->text('value');
        });

        Schema::connection('sink')->create('message_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->index()->constrained('messages')->cascadeOnDelete();
            $table->text('url');
            $table->string('label')->nullable();
        });

        Schema::connection('sink')->create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->index()->constrained('messages')->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes');
            $table->string('object_key');
        });
    }

    public function down(): void
    {
        Schema::connection('sink')->dropIfExists('message_attachments');
        Schema::connection('sink')->dropIfExists('message_links');
        Schema::connection('sink')->dropIfExists('message_headers');
        Schema::connection('sink')->dropIfExists('message_recipients');
    }
};
