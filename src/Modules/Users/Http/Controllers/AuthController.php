<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Modules\Users\Http\Requests\LoginRequest;
use Modules\Users\Http\Requests\RegisterRequest;
use Modules\Users\Models\User;
use Modules\Users\Services\OtpService;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'role' => 'customer',
        ]);

        $this->otp->send($user->email, OtpService::VERIFY_EMAIL);

        return response()->json([
            'message' => 'Account created. A 6-digit verification code was sent to your email.',
            'email' => $user->email,
        ], 201);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $this->otp->verify($data['email'], OtpService::VERIFY_EMAIL, $data['code'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        $token = auth('api')->login($user);

        return $this->respondWithToken($token, $user);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if ($user && ! $user->email_verified_at) {
            $this->otp->send($user->email, OtpService::VERIFY_EMAIL);
        }

        return response()->json([
            'message' => 'If the account exists and is not verified, a new code was sent.',
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $token = auth('api')->attempt($request->validated());

        if (! $token) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        $user = auth('api')->user();

        if (! $user->email_verified_at) {
            auth('api')->logout();

            return response()->json(['message' => 'Please verify your email first.'], 403);
        }

        return $this->respondWithToken($token, $user);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $this->otp->send($user->email, OtpService::RESET_PASSWORD);
        }

        return response()->json([
            'message' => 'If the email exists, a 6-digit reset code was sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $this->otp->verify($data['email'], OtpService::RESET_PASSWORD, $data['code'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user->password = $data['password'];

        if (! $user->email_verified_at) {
            $user->email_verified_at = now();
        }

        $user->save();

        return response()->json(['message' => 'Password has been reset successfully.']);
    }

    public function me(): JsonResponse
    {
        return response()->json(auth('api')->user());
    }

    public function refresh(): JsonResponse
    {
        $user = auth('api')->user();
        $token = auth('api')->refresh();

        return $this->respondWithToken($token, $user);
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function respondWithToken(string $token, User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $user,
        ], $status);
    }
}
