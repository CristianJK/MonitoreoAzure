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
            $table->string('action_code'); // daily_comment | daily_comment_manual
            $table->string('status')->default('Pendiente'); // Pendiente | Publicado | Rechazado | Reemplazado | Vencido | Error
            $table->string('requested_by'); // manual | batch | scheduled
            $table->string('resolved_by')->nullable();
            $table->json('payload')->nullable(); // propuesta + contexto (PRs, commits) enviado al agente
            $table->json('result')->nullable(); // respuesta de Azure al publicar
            $table->timestamps();
            $table->timestamp('resolved_at')->nullable();

            $table->index(['status', 'created_at']);
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
