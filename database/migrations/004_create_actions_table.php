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
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained();
            $table->foreignId('user_id')->nullable();
            $table->enum('action', ['shuffle', 'join', 'leave', 'rotate', 'pot', 'play', 'pass', 'deal', 'timeout', 'kick']);
            $table->integer('bet')->nullable();
            $table->integer('card')->nullable()->unsigned();
            $table->timestamp('time')->useCurrent();
            $table->index(['room_id', 'action', 'user_id', 'time']);
            $table->index(['user_id', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
