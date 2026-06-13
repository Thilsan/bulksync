<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->unsignedInteger('image_width')->default(1200)->after('image_size');
            $table->unsignedInteger('image_height')->default(1200)->after('image_width');
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropColumn(['image_width', 'image_height']);
        });
    }
};
