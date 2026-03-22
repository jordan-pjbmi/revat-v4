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
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('widget_type', 50);
            $table->tinyInteger('grid_x')->unsigned();
            $table->smallInteger('grid_y')->unsigned();
            $table->tinyInteger('grid_w')->unsigned();
            $table->tinyInteger('grid_h')->unsigned();
            $table->json('config');
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->timestamps();

            $table->index('dashboard_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
