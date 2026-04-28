use App\Models\User;
use Illuminate\Support\Facades\Gate;

protected function gate(): void
{
    Gate::define('viewTelescope', function (User $user) {
        return in_array($user->email, [
            'victor.hugo@crm.cartaoamigosaude.com.br',
        ]);
    });
}
