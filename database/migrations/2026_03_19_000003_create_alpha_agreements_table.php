<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alpha_agreements', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254);
            $table->string('agreement_version', 20);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->timestamps();

            $table->unique(['email', 'agreement_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alpha_agreements');
    }
};
