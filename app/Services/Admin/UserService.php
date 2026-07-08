<?php

namespace App\Services\Admin;

use App\Interfaces\UserRepositoryInterface;

class UserService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        protected UserRepositoryInterface $repo
    ){}

    public function getAllUsers()
    {
        return $this->repo
            ->all()
            ->toArray();    
    }

    public function getUser($id)
    {
        return $this->repo
            ->find($id)
            ->toArray();
    }

    public function createUser($data)
    {
        $data['password'] = bcrypt($data['password']);
        return $this->repo->create($data);
    }

    public function updateUser($id, $data)
    {
        if (isset($data['password']))
        {
            $data['password'] = bcrypt($data['password']);
        }

        return $this->repo->update($id, $data);
    }

    public function deleteUser($id)
    {
        return $this->repo->delete($id);
    }
}
