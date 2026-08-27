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
        Schema::create('checklist_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_metadata_id')->constrained('work_item_metadata')->cascadeOnDelete();
            $table->foreignId('checklist_definition_id')->constrained('checklist_definitions')->cascadeOnDelete();
            $table->string('status')->default('pending');  // pending | in_progress | done | failed | skipped
            $table->string('checked_by')->nullable(); // 'system' | 'agent:code-reviewer' | user_id
            $table->timestamp('checked_at')->nullable();            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_status');
    }
};
