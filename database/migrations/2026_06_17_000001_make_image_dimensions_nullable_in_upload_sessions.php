<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->unsignedInteger('image_width')->nullable()->default(null)->change();
            $table->unsignedInteger('image_height')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->unsignedInteger('image_width')->default(1200)->change();
            $table->unsignedInteger('image_height')->default(1200)->change();
        });
    }
};
