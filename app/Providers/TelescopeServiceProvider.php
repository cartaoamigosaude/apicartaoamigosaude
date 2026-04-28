<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

protected function gate(): void
{
    Gate::define('viewTelescope', function (User $user) {
        return in_array($user->email, [
            'victor.hugo@crm.cartaoamigosaude.com.br',
            'roberta.cavalheiro@cartaoamigosaude.com.br'
        ]);
    });
}
