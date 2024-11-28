<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ptp_expected_areas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('volume_id')->unsigned()->index();
            $table->foreign('volume_id')->references('id')->on('volumes')->onDelete('cascade');
            $table->integer('label_id')->unsigned()->index();
            $table->foreign('label_id')->references('id')->on('labels')->onDelete('cascade');
            $table->jsonb('areas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ptp_expected_areas');
    }
};
