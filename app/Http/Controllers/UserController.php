<?php

namespace App\Http\Controllers;

<<<<<<< HEAD
=======

use App\Http\Requests\AdminCreateUserRequest;
use App\Http\Requests\AdminUpdateUserRequest;
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
use App\HttpResponse;
use App\Models\User;
use Illuminate\Http\Request;




use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{

    use HttpResponse;

    public function index()
    {
        //
        return $this->success(["users" => User::all()->toArray()]);

<<<<<<< HEAD
=======

>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
    }

    /**
     * Store a newly created resource in storage.
     */
<<<<<<< HEAD
    public function store(Request $request)
=======

    public function store(AdminCreateUserRequest $request)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
    {
        //
    }

    

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
<<<<<<< HEAD
        return $this->success(["user" => User::find($id)->toArray()]);
=======

        return $this->success([
            "user" => $this->userService->getUser($id)
        ]);

        return $this->success(["user" => User::find($id)->toArray()]);

>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
    }

    /**
     * Update the specified resource in storage.
     */
<<<<<<< HEAD
    public function update(Request $request, string $id)
    {
        //
    }
=======

    public function update(AdminUpdateUserRequest $request, string $id)
    {
        $validated = $request->validated();
        $user = $this->userService->updateUser($id, $validated);
        return $this->success(['user' => $user]);

   
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4

    /**
     * Remove the specified resource from storage.
     */
<<<<<<< HEAD
    public function destroy(string $id)
    {
        //
    }

}
=======
   
}

        //
    }




>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
