<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection((string) config('sink-server.database.connection'));

        $schema->create('message_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->index()->constrained('messages')->cascadeOnDelete();
            $table->string('kind');
            $table->string('address');
            $table->string('name')->nullable();
        });

        $schema->create('message_headers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->index()->constrained('messages')->cascadeOnDelete();
            $table->string('name');
            $table->text('value');
        });

        $schema->create('message_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->index()->constrained('messages')->cascadeOnDelete();
            $table->text('url');
            $table->string('label')->nullable();
        });

        $schema->create('message_attachments', function (Blueprint $table): void {
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
        $schema = Schema::connection((string) config('sink-server.database.connection'));

        $schema->dropIfExists('message_attachments');
        $schema->dropIfExists('message_links');
        $schema->dropIfExists('message_headers');
        $schema->dropIfExists('message_recipients');
    }
};
