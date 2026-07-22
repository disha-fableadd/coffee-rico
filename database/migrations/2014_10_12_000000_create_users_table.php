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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['user', 'admin'])->default('user');
            $table->string('profileImage')->default('/uploads/default.png');
            $table->string('number')->unique();
            $table->enum('status', ['active', 'inactive', 'pending'])->default('active');
            $table->text('bio')->nullable();
            $table->string('companyName')->nullable();
            $table->string('companyLogo')->default('/uploads/company/default.png');
            $table->text('companyAddress')->nullable();
            $table->string('companyEmail')->nullable();
            $table->string('companyMobile')->nullable();
            $table->string('resetPasswordToken')->nullable();
            $table->timestamp('resetPasswordExpires')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
