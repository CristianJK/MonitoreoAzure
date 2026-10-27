<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada propuesta y ejecución del agente pertenece al usuario dueño del work item.
     */
    public function up(): void
    {
        Schema::table('agent_approvals', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('work_item_metadata_id')->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->after('resolved_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('agent_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropConstrainedForeignId('resolved_by_user_id');
        });
    }
};
