<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraped_departures', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_slug');
            $table->string('oacl_bus_id');
            $table->string('bus_name')->nullable();
            $table->string('origin');
            $table->string('destination');
            $table->date('travel_date');
            $table->string('departure_time', 5); // HH:MM, 24-hour
            $table->dateTime('departure_at');

            $table->string('upload_before_status')->default('pending'); // pending|success|failed
            $table->dateTime('upload_before_attempted_at')->nullable();

            $table->string('upload_after_status')->default('pending');
            $table->dateTime('upload_after_attempted_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_slug', 'oacl_bus_id', 'travel_date']);
            $table->index('departure_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraped_departures');
    }
};
