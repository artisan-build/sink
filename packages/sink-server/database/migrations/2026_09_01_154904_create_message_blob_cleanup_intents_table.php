<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection((string) config('sink-server.database.connection'))->create('message_blob_cleanup_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('object_key')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection((string) config('sink-server.database.connection'))->dropIfExists('message_blob_cleanup_intents');
    }
};
