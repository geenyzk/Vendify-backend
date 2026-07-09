<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic replacement for the fixed-column stock_vendings row: one row per
     * (service_type, route_key) pointing at the vendor that fulfils it. Because
     * a route is just data — not a column — a brand-new data plan_type, cable
     * network or disco becomes routable without a schema change. Resolution
     * (VTUServiceFactory → ServiceRoute::resolveVendor) treats this as an
     * override that falls back to the legacy stock_vendings assignment when a
     * key has no row, so existing behaviour is preserved.
     */
    public function up(): void
    {
        Schema::create('service_routes', function (Blueprint $table) {
            $table->id();
            // e.g. data, airtime, cable, electricity, exam
            $table->string('service_type');
            // The specific dimension within the service: a data plan_type
            // ("sme"), airtime category ("vtu"), cable network ("dstv"), disco
            // name ("Ikeja Electric"), or the service name for singletons
            // ("exam"). Empty string is allowed for services with no sub-key.
            $table->string('route_key')->default('');
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['service_type', 'route_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_routes');
    }
};
