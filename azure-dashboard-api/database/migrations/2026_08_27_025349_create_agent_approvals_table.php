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
    Schema::create('agent_approvals', function (Blueprint $table) {
        $table->id();
        $table->foreignId('work_item_metadata_id')->constrained('work_item_metadata')->cascadeOnDelete();
        $table->string('action_code');
        $table->string('status')->default('Pendiente');
        $table->string('requested_by');
        $table->string('resolved_by')->nullable();        
        $table->timestamps();
        $table->timestamp('resolved_at')->nullable();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_approvals');
    }
};
