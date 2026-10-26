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
        Schema::create('checklist_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('data_type'); // App\Enums\ChecklistDataType
            $table->json('options')->nullable(); // opciones cuando data_type = select
            $table->string('work_item_state'); // estado del WI en el que se debe cumplir el item
            $table->boolean('blocking')->default(false);
            $table->boolean('is_required')->default(true);
            $table->string('applies_to_type')->nullable(); // null = todos los tipos de WI
            $table->string('source')->default('manual'); // App\Enums\ChecklistSource
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_item_state', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_definitions');
    }
};
