<?php
namespace App\Rules;

use App\Support\PhoneNetwork;
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
        // The prefix table lives in PhoneNetwork so validation here and the
        // network recovered for older transactions cannot drift apart.
        return PhoneNetwork::matches($value, $this->network);
    }

    public function message()
    {
        return 'The phone number does not match the selected network.';
    }
}
