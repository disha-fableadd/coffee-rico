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
        // Drop the existing settings table
        Schema::dropIfExists('settings');
        
        // Recreate with correct structure matching Node.js
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('fb_app_id')->nullable();
            $table->string('fb_app_secret')->nullable();
            $table->string('phoneNumberId')->nullable();
            $table->string('waba_id')->nullable();
            $table->text('access_token')->nullable();
            $table->string('webhook_url')->nullable();
            $table->boolean('isActive')->default(true);
            $table->integer('expires_in')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the new settings table
        Schema::dropIfExists('settings');
        
        // Recreate with old structure
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->text('access_token')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('waba_id')->nullable();
            $table->string('fb_app_id')->nullable();
            $table->string('fb_app_secret')->nullable();
            $table->integer('expires_in')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }
};
