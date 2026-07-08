<?php

namespace App\Classes\VTUServices;

use App\Classes\Vendor\VendorFactory;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;

class VTUServiceFactory
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }


     static function make ($service='', $sub="") {
         $provider = Vendor::provider($sub ?? $service)->first();
        return match ($service) {
                "airtime"=> VendorFactory::make($provider),
                "data"=> VendorFactory::make($provider),
                "cable"=> VendorFactory::make($provider),
                "electricity"=> VendorFactory::make($provider),
                "airtimeToCash"=> VendorFactory::make($provider),
                "exam"=> VendorFactory::make($provider),
        } ;
    }
}
