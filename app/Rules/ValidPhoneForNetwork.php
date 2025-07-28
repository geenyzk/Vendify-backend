<?php
namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class ValidPhoneForNetwork implements Rule
{
    protected $network;

    public function __construct($network)
    {
        $this->network = strtolower($network);
    }

    public function passes($attribute, $value)
    {
        $prefix = substr(preg_replace('/\D/', '', $value), 0, 4); // Get first 4 digits only (remove + if present)

        $networkPrefixes = [
            'mtn' => ['0803', '0806', '0810', '0813', '0814', '0816', '0703', '0706', '0903', '0906', '0913', '0916'],
            'airtel' => ['0802', '0808', '0812', '0708', '0701', '0902', '0907', '0901', '0912'],
            'glo' => ['0805', '0807', '0811', '0815', '0705', '0905', '0915'],
            '9mobile' => ['0809', '0817', '0818', '0909', '0908']
        ];

        return in_array($prefix, $networkPrefixes[$this->network] ?? []);
    }

    public function message()
    {
        return 'The phone number does not match the selected network.';
    }
}
