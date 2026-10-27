<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los usuarios inician sesión con su PAT personal de Azure DevOps: `email`
     * guarda su uniqueName, `azure_id` el id de su identidad y `azure_pat` el
     * PAT cifrado (también lo usa la ejecución programada del agente).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('azure_id')->nullable()->unique()->after('id');
            $table->string('password')->nullable()->change();
            $table->text('azure_pat')->nullable();
            $table->boolean('agent_schedule_enabled')->default(false);
            $table->timestamp('last_login_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['azure_id', 'azure_pat', 'agent_schedule_enabled', 'last_login_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
