<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class AirtimeToCashProviderSetting extends Model
{
    use Auditable;

    protected $table = 'airtime_to_cash_provider_settings';

    protected $primaryKey = 'provider';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['provider', 'enabled', 'priority'];

    protected $casts = ['enabled' => 'boolean', 'priority' => 'integer'];
}
