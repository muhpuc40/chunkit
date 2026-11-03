<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Published (not auto-loaded) with:
 *   php artisan vendor:publish --tag=chunkit-migrations
 *
 * The status values are written out here on purpose instead of referencing the
 * model, so this file keeps meaning the same thing after the package changes.
 *
 * This package never creates or touches the jobs, job_batches or failed_jobs
 * tables: the host app already owns them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunked_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('upload_id')->unique();
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('chunk_size');
            $table->unsignedBigInteger('total_bytes');
            $table->json('received_chunks');
            $table->enum('status', ['pending', 'uploading', 'merging', 'completed', 'failed'])
                ->default('pending');
            $table->string('destination_path', 1024)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            // Serves chunkit:prune, which looks for unfinished uploads past their expiry.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunked_uploads');
    }
};
