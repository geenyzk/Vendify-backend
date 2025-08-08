<?php

namespace App\Repository\Admin;

use App\Models\User;
use App\Interfaces\UserRepositoryInterface;

class UserRepository implements UserRepositoryInterface
{
    public function all()
    {
        return User::all();    
    }

    public function find($id)
    {
        return User::findorFail($id);
    }

    public function create(array $data)
    {
        return User::create($data);
    }

    public function update($id, array $data)
    {
        $user = $this->find($id);
        $user->update($data);
        return $user;
    }

    public function delete($id)
    {
        $user = $this->find($id);
        $user->delete();
        return true;
    }
}
