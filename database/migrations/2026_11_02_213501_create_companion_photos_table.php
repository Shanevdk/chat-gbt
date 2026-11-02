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
        Schema::create('companion_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('companion_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companion_photos');
    }
};
