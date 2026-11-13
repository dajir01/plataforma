<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Support\EmpresaEventoAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class EmpresaAuthController extends Controller
{
    public function __construct(private readonly EmpresaEventoAccess $empresaEventoAccess) {}

    /**
     * Login de empresas. Prioridad estricta: primero NIT, luego usuario
     * (solo si el NIT no existe). Nunca se combinan ambas búsquedas.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'identificador' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $identificador = $credentials['identificador'];
        $password = $credentials['password'];

        $empresa = Empresa::query()->where('nit', $identificador)->first();

        if (! $empresa) {
            $empresa = Empresa::query()->where('usuario', $identificador)->first();
        }

        if (! $empresa || ! $this->verifyPassword($password, $empresa->pass)) {
            Log::warning('Intento de login de empresa fallido', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Credenciales incorrectas. Verifique su NIT/usuario y contraseña.',
            ], 422);
        }

        if (! $this->isActive($empresa)) {
            Log::warning('Intento de login de empresa inactiva', [
                'id_empresa' => $empresa->id_empresa,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'La cuenta de la empresa se encuentra inactiva.',
            ], 422);
        }

        if (! $this->tieneContratoEventoProximo($empresa)) {
            Log::warning('Intento de login de empresa sin contrato con evento próximo', [
                'id_empresa' => $empresa->id_empresa,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Esta empresa no tiene un contrato vigente con ningún evento próximo.',
            ], 403);
        }

        Auth::guard('empresa')->login($empresa, $request->boolean('remember'));
        $request->session()->regenerate();

        Log::info('Login de empresa exitoso', [
            'id_empresa' => $empresa->id_empresa,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'user' => $this->buildAuthenticatedPayload($empresa),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('empresa')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Sesión cerrada',
        ]);
    }

    public function me(Request $request)
    {
        $empresa = Auth::guard('empresa')->user();

        return response()->json([
            'user' => $empresa ? $this->buildAuthenticatedPayload($empresa) : null,
        ]);
    }

    protected function verifyPassword(string $plain, ?string $stored): bool
    {
        if (is_string($stored) && str_starts_with($stored, '$2y$')) {
            return Hash::check($plain, $stored);
        }

        return $stored !== null && $stored !== '' && hash_equals($stored, $plain);
    }

    protected function isActive(Empresa $empresa): bool
    {
        if (is_null($empresa->getRawOriginal('es_activo'))) {
            return true;
        }

        return (bool) $empresa->es_activo;
    }

    /**
     * Misma regla utilizada por AcreditacionController@eventos: la empresa debe tener
     * un contrato o vinculación activa con un evento cuya fecha de fin no haya pasado.
     */
    protected function tieneContratoEventoProximo(Empresa $empresa): bool
    {
        return $this->empresaEventoAccess->eventosDisponibles($empresa)->exists();
    }

    private function buildAuthenticatedPayload(Empresa $empresa): array
    {
        return [
            'id_empresa' => $empresa->id_empresa,
            'nit' => $empresa->nit,
            'usuario' => $empresa->usuario,
            'nombre_empresa' => $empresa->nombre_empresa,
        ];
    }
}
