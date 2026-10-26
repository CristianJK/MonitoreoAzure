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
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->string('azure_work_item_id');
            $table->date('run_date');
            $table->string('trigger'); // manual | batch | scheduled
            $table->uuid('batch_id')->nullable();
            $table->string('status'); // queued | proposal | manual_required | error
            $table->json('summary')->nullable();
            $table->foreignId('agent_approval_id')->nullable()->constrained('agent_approvals')->nullOnDelete();
            $table->string('azure_comment_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['azure_work_item_id', 'run_date', 'trigger']);
            $table->index('batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
