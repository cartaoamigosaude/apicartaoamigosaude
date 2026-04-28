use App\Models\User;
use Illuminate\Support\Facades\Gate;

protected function gate(): void
{
    Gate::define('viewTelescope', function (User $user) {
        return in_array($user->email, [
            'victor.hugo@cartaoamigosaude.com.br',
            'roberta.cavalheiro@cartaoamigosaude.com.br',
        ]);
    });
}
