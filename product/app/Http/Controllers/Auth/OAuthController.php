<?php

namespace App\Http\Controllers\Auth;

use App\Application\AuthIdentityService;
use App\Application\ExternalIdentityData;
use App\Http\Controllers\Controller;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use LogicException;
use Throwable;

class OAuthController extends Controller
{
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->assertProvider($provider);
        $request->session()->put('oauth_intent', 'login');

        return $this->socialiteProvider($provider)->setScopes($this->scopes($provider))->redirect();
    }

    public function link(Request $request, string $provider): RedirectResponse
    {
        $this->assertProvider($provider);
        $request->session()->put('oauth_intent', 'link');

        return $this->socialiteProvider($provider)->setScopes($this->scopes($provider))->redirect();
    }

    public function callback(Request $request, string $provider, AuthIdentityService $identities): RedirectResponse
    {
        $this->assertProvider($provider);
        $intent = $request->session()->pull('oauth_intent', 'login');
        $linkTo = $intent === 'link' ? $request->user() : null;

        if ($intent === 'link' && ! $linkTo instanceof User) {
            return redirect('/?oauth=link-session-expired')
                ->with('oauth_error', 'アカウント連携を続けるには再ログインしてください。');
        }

        try {
            $external = $this->socialiteProvider($provider)->setScopes($this->scopes($provider))->user();
            $user = $identities->authenticate(
                $provider,
                new ExternalIdentityData((string) $external->getId(), (string) ($external->getName() ?: $external->getNickname() ?: ucfirst($provider).' user')),
                $linkTo,
            );
            Auth::login($user, true);
            $request->session()->regenerate();

            return redirect('/?oauth=success');
        } catch (DomainException $exception) {
            return redirect('/?oauth=conflict')->with('oauth_error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect('/?oauth=failed')->with(
                'oauth_error',
                '認証サービスで一時的な障害が発生しています。しばらく待ってから再試行してください。事前に別の認証サービスを連携済みの場合は、そちらからもログインできます。',
            );
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function assertProvider(string $provider): void
    {
        abort_unless(in_array($provider, ['discord', 'google'], true), 404);
        abort_if(blank(config("services.{$provider}.client_id")) || blank(config("services.{$provider}.client_secret")), 503, 'OAuth provider is not configured.');
    }

    /** @return list<string> */
    private function scopes(string $provider): array
    {
        return $provider === 'discord' ? ['identify'] : ['openid', 'profile'];
    }

    private function socialiteProvider(string $provider): AbstractProvider
    {
        $driver = Socialite::driver($provider);

        if (! $driver instanceof AbstractProvider) {
            throw new LogicException("{$provider} must use an OAuth 2 Socialite provider.");
        }

        return $driver;
    }
}
