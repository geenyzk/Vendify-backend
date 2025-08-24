<?php

namespace App\Providers;

<<<<<<< HEAD
=======
<<<<<<< HEAD
use App\Interfaces\UserRepositoryInterface;
use App\Repository\Admin\UserRepository;
=======
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
<<<<<<< HEAD
        //
=======
<<<<<<< HEAD
        $this->app
            ->bind(UserRepositoryInterface::class, UserRepository::class);
=======
        //
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
