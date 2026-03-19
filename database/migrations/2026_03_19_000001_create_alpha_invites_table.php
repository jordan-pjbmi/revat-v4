<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alpha_invites', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254)->unique();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_sent_at');
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alpha_invites');
    }
};
