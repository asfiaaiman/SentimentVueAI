<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('product')->index();
            $table->unsignedTinyInteger('rating')->nullable()->index();
            $table->longText('text');
            $table->string('sentiment_label')->nullable()->index();
            $table->float('sentiment_confidence')->nullable();
            $table->string('emotion_label')->nullable()->index();
            $table->float('emotion_confidence')->nullable();
            $table->timestamp('analyzed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};


