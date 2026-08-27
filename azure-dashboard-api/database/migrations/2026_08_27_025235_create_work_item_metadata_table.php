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
        Schema::create('work_item_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('azure_work_item_id')->unique();
            $table->string('work_item_type');
            $table->dateTime('estimated_delivery_date')->nullable();
            $table->dateTime('possible_pap_date')->nullable();
            $table->boolean('ready_to_deploy')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_item_metadata');
    }
};
