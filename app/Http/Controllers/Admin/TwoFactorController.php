<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;

/**
 * Enrol, confirm and challenge an admin's second factor.
 *
 * A stolen admin session reached billing, customer records and the MCC
 * credentials the entire platform authenticates with, with no second factor in
 * the way and no rate limit either.
 *
 * Enrolment is two steps on purpose. Generating a secret proves nothing; the
 * account is only protected once a code from the authenticator has been checked,
 * and treating an unconfirmed secret as enrolment would lock people out of the
 * console over a QR code they never scanned.
 */
class TwoFactorController extends Controller
{
    public function __construct(private Google2FA $google2fa) {}

    /**
     * Show enrolment, or the current state if already enrolled.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Admin/TwoFactor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'confirmedAt' => $user->two_factor_confirmed_at,
            'recoveryCodesRemaining' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    /**
     * Generate a secret and show the QR code. Not yet in force.
     */
    public function enrol(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Two-factor is already enabled.']);
        }

        $secret = $this->google2fa->generateSecretKey();

        // Stored unconfirmed: hasTwoFactorEnabled() stays false until a code is
        // verified, so a half-finished enrolment cannot lock anyone out.
        // forceFill, not update: the two-factor columns are deliberately absent
        // from $fillable so that no request can ever mass-assign a secret.
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return Inertia::render('Admin/TwoFactor', [
            'enabled' => false,
            'setupSecret' => $secret,
            'setupQr' => $this->qrCode($user, $secret),
        ]);
    }

    /**
     * Verify a code from the authenticator and switch enforcement on.
     */
    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();

        if (! $user->two_factor_secret) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Start setup before confirming.']);
        }

        if (! $this->google2fa->verifyKey($user->two_factor_secret, $request->string('code')->toString())) {
            return back()->withErrors(['code' => 'That code is not valid. Check your authenticator and try again.']);
        }

        // Single-use codes for the day the phone is lost. Hashed would be
        // better still, but they are encrypted at rest and shown exactly once.
        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        Log::info('Admin enabled two-factor', ['user_id' => $user->id]);

        return Inertia::render('Admin/TwoFactor', [
            'enabled' => true,
            'confirmedAt' => $user->two_factor_confirmed_at,
            'recoveryCodes' => $recoveryCodes,
            'recoveryCodesRemaining' => count($recoveryCodes),
        ]);
    }

    /**
     * The per-session challenge, shown once after login.
     */
    public function challenge()
    {
        return Inertia::render('Admin/TwoFactorChallenge');
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();
        $code = $request->string('code')->toString();

        if ($this->google2fa->verifyKey((string) $user->two_factor_secret, $code)) {
            $request->session()->put('two_factor_passed_at', now()->timestamp);

            return redirect()->intended(route('admin.dashboard'));
        }

        // Recovery codes are single use — consuming one is the point, otherwise
        // a leaked list stays valid forever.
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalised = Str::upper(trim($code));

        if (in_array($normalised, $codes, true)) {
            $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$normalised]))])->save();
            $request->session()->put('two_factor_passed_at', now()->timestamp);

            Log::warning('Admin used a two-factor recovery code', [
                'user_id' => $user->id,
                'remaining' => count($codes) - 1,
            ]);

            return redirect()->intended(route('admin.dashboard'));
        }

        return back()->withErrors(['code' => 'That code is not valid.']);
    }

    /**
     * Turn it off. Requires a current code, so a hijacked session cannot simply
     * disable the thing standing in its way.
     */
    public function disable(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();

        if (! $this->google2fa->verifyKey((string) $user->two_factor_secret, $request->string('code')->toString())) {
            return back()->withErrors(['code' => 'Enter a current code to turn two-factor off.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Log::warning('Admin disabled two-factor', ['user_id' => $user->id]);

        return redirect()->route('admin.two-factor.show')
            ->with('flash', ['type' => 'success', 'message' => 'Two-factor disabled.']);
    }

    private function qrCode(User $user, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);

        $writer = new Writer(new ImageRenderer(new RendererStyle(256), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url));
    }
}
