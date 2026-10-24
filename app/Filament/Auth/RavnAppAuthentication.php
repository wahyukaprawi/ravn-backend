<?php

namespace App\Filament\Auth;

use App\Services\MfaService;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use SensitiveParameter;

class RavnAppAuthentication extends AppAuthentication
{
    public function verifyCode(
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] ?string $secret = null,
        bool $shouldPreventCodeReuse = false,
    ): bool {
        $user = Filament::auth()->user();
        $currentSecret = $user instanceof HasAppAuthentication
            ? $user->getAppAuthenticationSecret()
            : null;

        if ($secret !== null && $secret !== $currentSecret) {
            return $this->google2FA->verifyKey($secret, $code, $this->getCodeWindow());
        }

        return app(MfaService::class)->verify($user, $code);
    }
}
