<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarCredencialDigitalJob;
use App\Models\EmpresaEventoCredencial;
use App\Models\Feria;
use App\Support\EmpresaEventoAccess;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AcreditacionController extends Controller
{
    public function __construct(private readonly EmpresaEventoAccess $empresaEventoAccess) {}

    public function buscarExpositor(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'ci' => ['required', 'string', 'max:50'],
            'id_feria' => ['required', 'integer'],
        ]);
        $empresa = Auth::guard('empresa')->user();
        abort_unless($empresa && $this->empresaEventoAccess->puedeAcceder($empresa, (int) $datos['id_feria']), 404);

        $expositor = DB::table('expositores')
            ->where('ci', trim($datos['ci']))
            ->first(['id_exp', 'ci', 'tipo_documento', 'nombre', 'email', 'com_email', 'celular', 'foto']);
        $credencialEvento = $expositor
            ? DB::table('credenciales as credencial')
                ->join('empresas as empresa', 'empresa.id_empresa', '=', 'credencial.id_empresa')
                ->where('credencial.id_expositor', $expositor->id_exp)
                ->where('credencial.id_feria', $request->integer('id_feria'))
                ->where('credencial.estado_credencial', '!=', 3)
                ->first(['empresa.nombre_empresa', 'credencial.tipo_credencial'])
            : null;

        return response()->json([
            'success' => true,
            'data' => $expositor ? [
                'id_expositor' => (int) $expositor->id_exp,
                'ci' => $expositor->ci,
                'tipo_documento' => $expositor->tipo_documento,
                'nombre' => $expositor->nombre,
                'email' => $expositor->email ?: $expositor->com_email,
                'celular' => $expositor->celular,
                'foto' => $expositor->foto,
                'credencial_evento' => $credencialEvento ? [
                    'empresa' => $credencialEvento->nombre_empresa,
                    'tipo' => match ((int) $credencialEvento->tipo_credencial) {
                        1 => 'Expositor', 2 => 'Prensa', 3 => 'Oficial', 4 => 'Servicios', 5 => 'Negocios',
                        default => 'Sin tipo definido',
                    },
                ] : null,
            ] : null,
        ]);
    }

    public function mostrarFotoAcreditado(string $filename)
    {
        abort_unless(preg_match('/^acreditado_[a-f0-9-]+\.(jpg|jpeg|png)$/i', $filename), 404);

        $empresa = Auth::guard('empresa')->user();
        abort_unless($empresa, 401);

        $fotoAutorizada = DB::table('credenciales as credencial')
            ->join('expositores as expositor', 'expositor.id_exp', '=', 'credencial.id_expositor')
            ->where('credencial.id_empresa', $empresa->id_empresa)
            ->where('expositor.foto', 'images/fotos/'.$filename)
            ->where('credencial.estado_credencial', '!=', 3)
            ->exists();
        abort_unless($fotoAutorizada, 404);

        $disk = Storage::disk('acreditados');
        if (! $disk->exists($filename)) {
            $legacyDisk = Storage::disk('acreditados_legacy');
            abort_unless($legacyDisk->exists($filename), 404);
            $disk = $legacyDisk;
        }

        return response()->file($disk->path($filename), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function eventos(): JsonResponse
    {
        $empresa = Auth::guard('empresa')->user();

        if (! $empresa) {
            return response()->json(['message' => 'La sesión no es válida.'], 401);
        }

        $eventos = $this->empresaEventoAccess->eventosDisponibles($empresa)
            ->select('eventos.id_feria', 'eventos.nombre_feria', 'eventos.codigo_contrato', 'eventos.fecha_inicio', 'eventos.fecha_fin')
            ->orderByRaw('COALESCE(eventos.fecha_inicio, eventos.inicio) ASC')
            ->orderBy('eventos.id_feria')
            ->get();

        return response()->json(['success' => true, 'data' => $eventos]);
    }

    public function resumen(int $idFeria): JsonResponse
    {
        $empresa = Auth::guard('empresa')->user();

        if (! $empresa) {
            return response()->json(['message' => 'La sesión no es válida.'], 401);
        }

        abort_unless($this->empresaEventoAccess->puedeAcceder($empresa, $idFeria), 404);

        $feria = Feria::query()
            ->select('id_feria', 'nombre_feria', 'codigo_contrato', 'fecha_inicio', 'fecha_fin', 'tipo_credenciales')
            ->where(function ($query) {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', today());
            })
            ->findOrFail($idFeria);
        $tipos = collect(explode(';', (string) $feria->tipo_credenciales))
            ->map(fn ($tipo) => trim((string) $tipo))
            ->filter()
            ->unique()
            ->values();

        if ($tipos->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => ['evento' => $feria, 'tipos' => []],
            ]);
        }

        $contratos = DB::table('contrato')
            ->where('id_empresa', $empresa->id_empresa)
            ->where('id_feria', $idFeria)
            ->where('codigo_contrato', '>', 0)
            ->where('total_stands', '>', 0)
            ->get(['id_contrato', 'codigo_contrato', 'tipo_credenciales', 'nro_credenciales', 'stand_1', 'metraje_total']);
        $vinculaciones = EmpresaEventoCredencial::query()
            ->where('id_empresa', $empresa->id_empresa)
            ->where('id_feria', $idFeria)
            ->where('estado', true)
            ->whereIn('tipo_credencial', $tipos)
            ->get(['id', 'id_contrato', 'tipo_credencial', 'cantidad_credenciales']);

        if ($contratos->isEmpty() && $vinculaciones->isEmpty()) {
            abort(404, 'El evento no pertenece a la empresa autenticada.');
        }

        $vinculacionesPorContrato = $vinculaciones->whereNotNull('id_contrato')->keyBy('id_contrato');
        $movimientos = DB::table('cred_adicionales')
            ->where('feria', $idFeria)
            ->where('id_empresa', $empresa->id_empresa)
            ->get(['contrato', 'id_empresa_evento_credencial', 'cant_adic', 'tipo_movimiento']);
        $asignadas = $tipos->mapWithKeys(fn ($tipo) => [$tipo => 0]);

        foreach ($contratos as $contrato) {
            $vinculacion = $vinculacionesPorContrato->get($contrato->id_contrato);
            $tipo = $vinculacion?->tipo_credencial ?? $tipos->first();
            $adicionales = $movimientos
                ->where('contrato', $contrato->id_contrato)
                ->whereNull('id_empresa_evento_credencial')
                ->sum(fn ($movimiento) => $movimiento->tipo_movimiento === 'disminucion'
                    ? -(int) $movimiento->cant_adic
                    : (int) $movimiento->cant_adic);
            $asignadas[$tipo] += (int) ($vinculacion?->cantidad_credenciales ?? $contrato->nro_credenciales ?? 0) + $adicionales;
        }

        foreach ($vinculaciones->whereNull('id_contrato') as $vinculacion) {
            $adicionales = $movimientos
                ->where('id_empresa_evento_credencial', $vinculacion->id)
                ->whereNull('contrato')
                ->where('tipo_movimiento', 'adicion')
                ->sum('cant_adic');
            $asignadas[$vinculacion->tipo_credencial] += (int) $vinculacion->cantidad_credenciales + (int) $adicionales;
        }

        $contratoIds = $contratos->pluck('id_contrato');
        $usadasPorContrato = DB::table('credenciales')
            ->whereIn('id_contrato', $contratoIds)
            ->where('estado_credencial', '!=', 3)
            ->select('id_contrato', DB::raw('COUNT(*) as total'))
            ->groupBy('id_contrato')
            ->pluck('total', 'id_contrato');
        $usadasDirectas = DB::table('credenciales')
            ->where('id_empresa', $empresa->id_empresa)
            ->where('id_feria', $idFeria)
            ->whereNull('id_contrato')
            ->where('estado_credencial', '!=', 3)
            ->select('tipo_credencial', DB::raw('COUNT(*) as total'))
            ->groupBy('tipo_credencial')
            ->pluck('total', 'tipo_credencial');
        $usadas = $tipos->mapWithKeys(fn ($tipo) => [$tipo => 0]);

        foreach ($contratos as $contrato) {
            $tipo = $vinculacionesPorContrato->get($contrato->id_contrato)?->tipo_credencial ?? $tipos->first();
            $usadas[$tipo] += (int) ($usadasPorContrato->get($contrato->id_contrato) ?? 0);
        }
        foreach ($vinculaciones->whereNull('id_contrato') as $vinculacion) {
            $codigo = match (mb_strtolower($vinculacion->tipo_credencial)) {
                'expositor' => 1, 'prensa' => 2, 'oficial' => 3, 'servicios' => 4, 'negocios' => 5,
                default => null,
            };
            $usadas[$vinculacion->tipo_credencial] += (int) ($usadasDirectas->get($codigo) ?? 0);
        }

        $acreditadosPorContrato = DB::table('credenciales as credencial')
            ->join('expositores as expositor', 'expositor.id_exp', '=', 'credencial.id_expositor')
            ->where('credencial.id_empresa', $empresa->id_empresa)
            ->where('credencial.id_feria', $idFeria)
            ->whereIn('credencial.id_contrato', $contratoIds)
            ->where('credencial.estado_credencial', '!=', 3)
            ->select(
                'credencial.id_credencial',
                'credencial.id_contrato',
                'credencial.modalidad_credencial',
                'credencial.estado_credencial',
                'expositor.nombre',
                'expositor.ci',
                'expositor.exp',
                'expositor.celular',
                'expositor.email',
                'expositor.com_email',
                'expositor.tipo_documento',
                'expositor.foto'
            )
            ->orderBy('expositor.nombre')
            ->get()
            ->groupBy('id_contrato');
        $acreditadosDirectosPorTipo = DB::table('credenciales as credencial')
            ->join('expositores as expositor', 'expositor.id_exp', '=', 'credencial.id_expositor')
            ->where('credencial.id_empresa', $empresa->id_empresa)
            ->where('credencial.id_feria', $idFeria)
            ->whereNull('credencial.id_contrato')
            ->where('credencial.estado_credencial', '!=', 3)
            ->select(
                'credencial.id_credencial',
                'credencial.tipo_credencial',
                'credencial.modalidad_credencial',
                'credencial.estado_credencial',
                'expositor.nombre',
                'expositor.ci',
                'expositor.exp',
                'expositor.celular',
                'expositor.email',
                'expositor.com_email',
                'expositor.tipo_documento',
                'expositor.foto'
            )
            ->orderBy('expositor.nombre')
            ->get()
            ->groupBy('tipo_credencial');
        $ubicaciones = DB::table('stands')
            ->leftJoin('pabellones', 'pabellones.id_pabellon', '=', 'stands.id_pabellon')
            ->whereIn('stands.id_stand', $contratos->pluck('stand_1')->filter()->unique())
            ->get(['stands.id_stand', 'stands.numero_stand', 'pabellones.nombre_pabellon'])
            ->keyBy('id_stand');
        $descargasPorCredencial = Schema::hasTable('credencial_digital_descargas')
            ? DB::table('credencial_digital_descargas')
                ->whereIn('id_credencial', $acreditadosPorContrato->flatten()->pluck('id_credencial')->merge($acreditadosDirectosPorTipo->flatten()->pluck('id_credencial')))
                ->select('id_credencial', DB::raw('COUNT(*) as cantidad'), DB::raw('MAX(created_at) as ultima_fecha'))
                ->groupBy('id_credencial')
                ->get()
                ->keyBy('id_credencial')
            : collect();

        $mapearAcreditado = function ($acreditado, ?int $modalidadBase = null) use ($descargasPorCredencial): array {
            $modalidad = $acreditado->modalidad_credencial ?? $modalidadBase;

            return [
                'id_credencial' => (int) $acreditado->id_credencial,
                'nombre' => $acreditado->nombre,
                'ci' => $acreditado->ci,
                'exp' => $acreditado->exp,
                'celular' => $acreditado->celular,
                'email' => $acreditado->email ?: $acreditado->com_email,
                'tipo_documento' => $acreditado->tipo_documento,
                'foto' => $acreditado->foto,
                'modalidad_credencial' => (int) ($modalidad ?? 1) === 1 ? 'Digital' : 'Física',
                'estado_credencial_codigo' => (int) $acreditado->estado_credencial,
                'estado_credencial' => match ((int) $acreditado->estado_credencial) {
                    1 => 'Activo', 2 => 'Anulado', 0 => 'Inhabilitado', default => 'Eliminado',
                },
                'descargas' => $descargasPorCredencial->get($acreditado->id_credencial),
            ];
        };

        $registros = collect();
        foreach ($contratos as $contrato) {
            $vinculacion = $vinculacionesPorContrato->get($contrato->id_contrato);
            $tipo = $vinculacion?->tipo_credencial ?? $tipos->first();
            $modalidad = $vinculacion?->tipo_credenciales ?? $contrato->tipo_credenciales;
            $asignadasContrato = (int) ($vinculacion?->cantidad_credenciales ?? $contrato->nro_credenciales ?? 0);
            $adicionalesContrato = $movimientos
                ->where('contrato', $contrato->id_contrato)
                ->whereNull('id_empresa_evento_credencial')
                ->sum(fn ($movimiento) => $movimiento->tipo_movimiento === 'disminucion'
                    ? -(int) $movimiento->cant_adic
                    : (int) $movimiento->cant_adic);
            $acreditados = $acreditadosPorContrato->get($contrato->id_contrato, collect());
            $ubicacion = $ubicaciones->get($contrato->stand_1);

            $registros->push([
                'id_contrato' => (int) $contrato->id_contrato,
                'id_vinculacion' => $vinculacion?->id ? (int) $vinculacion->id : null,
                'codigo_contrato' => (string) $feria->codigo_contrato.str_pad((string) $contrato->codigo_contrato, 5, '0', STR_PAD_LEFT),
                'pabellon' => $ubicacion?->nombre_pabellon,
                'stands' => $ubicacion ? [$ubicacion->numero_stand] : [],
                'metraje_total' => (float) ($contrato->metraje_total ?? 0),
                'tipo_credencial' => $tipo,
                'modalidad_credencial' => (int) ($modalidad ?? 1) === 1 ? 'Digital' : 'Física',
                'credenciales_asignadas' => max(0, $asignadasContrato + (int) $adicionalesContrato),
                'credenciales_utilizadas' => $acreditados->count(),
                'credenciales_disponibles' => max(0, $asignadasContrato + (int) $adicionalesContrato - $acreditados->count()),
                'acreditados' => $acreditados->map(fn ($acreditado) => $mapearAcreditado($acreditado, (int) ($modalidad ?? 1)))->values(),
            ]);
        }

        foreach ($vinculaciones->whereNull('id_contrato') as $vinculacion) {
            $tipoCodigo = match (mb_strtolower($vinculacion->tipo_credencial)) {
                'expositor' => 1, 'prensa' => 2, 'oficial' => 3, 'servicios' => 4, 'negocios' => 5,
                default => null,
            };
            $acreditados = $acreditadosDirectosPorTipo->get($tipoCodigo, collect());
            $adicionales = $movimientos
                ->where('id_empresa_evento_credencial', $vinculacion->id)
                ->whereNull('contrato')
                ->where('tipo_movimiento', 'adicion')
                ->sum('cant_adic');
            $asignadasDirectas = (int) $vinculacion->cantidad_credenciales + (int) $adicionales;

            $registros->push([
                'id_contrato' => null,
                'id_vinculacion' => (int) $vinculacion->id,
                'codigo_contrato' => 'Sin contrato',
                'tipo_credencial' => $vinculacion->tipo_credencial,
                'modalidad_credencial' => (int) ($vinculacion->tipo_credenciales ?? 1) === 1 ? 'Digital' : 'Física',
                'credenciales_asignadas' => max(0, $asignadasDirectas),
                'credenciales_utilizadas' => $acreditados->count(),
                'credenciales_disponibles' => max(0, $asignadasDirectas - $acreditados->count()),
                'acreditados' => $acreditados->map(fn ($acreditado) => $mapearAcreditado($acreditado, (int) ($vinculacion->tipo_credenciales ?? 1)))->values(),
            ]);
        }

        $resumen = $tipos->map(fn ($tipo) => [
            'tipo' => $tipo,
            'asignadas' => max(0, (int) $asignadas[$tipo]),
            'utilizadas' => (int) $usadas[$tipo],
            'disponibles' => max(0, (int) $asignadas[$tipo] - (int) $usadas[$tipo]),
            'registros' => $registros->where('tipo_credencial', $tipo)->values(),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => ['evento' => $feria, 'tipos' => $resumen],
        ]);
    }

    public function acreditar(Request $request, int $idFeria): JsonResponse
    {
        $empresa = Auth::guard('empresa')->user();
        if (! $empresa) {
            return response()->json(['message' => 'La sesión no es válida.'], 401);
        }

        $datos = $request->validate([
            'id_contrato' => ['nullable', 'integer'],
            'id_vinculacion' => ['nullable', 'integer'],
            'tipo_credencial' => ['required', 'string', 'max:100'],
            'ci' => ['required', 'string', 'max:50'],
            'tipo_documento' => ['required', 'string', 'max:50'],
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'celular' => ['nullable', 'string', 'max:50'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);
        $datos['nombre'] = $this->normalizarNombre($datos['nombre']);
        $celular = trim((string) ($datos['celular'] ?? ''));
        if ($celular !== '' && ! preg_match('/^\+591\d{8}$|^\+\d{7,14}$/', $celular)) {
            throw ValidationException::withMessages([
                'celular' => 'El número de Bolivia debe tener 8 dígitos. Otros países deben tener entre 7 y 14 dígitos.',
            ]);
        }

        $fotoAlmacenada = null;
        try {
            $resultado = DB::transaction(function () use ($datos, $empresa, $idFeria, &$fotoAlmacenada): array {
                $feria = Feria::query()->lockForUpdate()->findOrFail($idFeria);
                if ($feria->fecha_fin && $feria->fecha_fin < today()->toDateString()) {
                    throw ValidationException::withMessages(['evento' => 'El evento ya finalizó.']);
                }

                $tipo = trim($datos['tipo_credencial']);
                $tipos = collect(explode(';', (string) $feria->tipo_credenciales))
                    ->map(fn ($item) => trim((string) $item))->filter()->values();
                if (! $tipos->contains($tipo)) {
                    throw ValidationException::withMessages(['tipo_credencial' => 'El tipo no está habilitado para este evento.']);
                }

                $idContrato = ! empty($datos['id_contrato']) ? (int) $datos['id_contrato'] : null;
                $idVinculacion = ! empty($datos['id_vinculacion']) ? (int) $datos['id_vinculacion'] : null;
                $contrato = $idContrato
                    ? DB::table('contrato')->where('id_contrato', $idContrato)->where('id_empresa', $empresa->id_empresa)->where('id_feria', $idFeria)->where('codigo_contrato', '>', 0)->where('total_stands', '>', 0)->lockForUpdate()->first()
                    : null;
                if ($idContrato && ! $contrato) {
                    throw ValidationException::withMessages(['id_contrato' => 'El contrato no pertenece a la empresa o no está disponible.']);
                }
                $vinculacionQuery = DB::table('empresa_evento_credenciales')
                    ->where('id_empresa', $empresa->id_empresa)
                    ->where('id_feria', $idFeria)
                    ->where('tipo_credencial', $tipo)
                    ->where('estado', true)
                    ->when($idContrato, fn ($query) => $query->where('id_contrato', $idContrato), fn ($query) => $query->whereNull('id_contrato'));
                if ($idVinculacion) {
                    $vinculacionQuery->where('id', $idVinculacion);
                }
                $vinculacion = $vinculacionQuery
                    ->lockForUpdate()
                    ->first();
                if ($idVinculacion && ! $vinculacion) {
                    throw ValidationException::withMessages(['id_vinculacion' => 'La asignación no pertenece a la empresa o no está disponible.']);
                }
                if (! $contrato && ! $vinculacion) {
                    throw ValidationException::withMessages(['evento' => 'La empresa no participa en este evento con ese tipo de credencial.']);
                }

                $tipoCodigo = match (mb_strtolower($tipo)) {
                    'expositor' => 1, 'prensa' => 2, 'oficial' => 3, 'servicios' => 4, 'negocios' => 5,
                    default => throw ValidationException::withMessages(['tipo_credencial' => 'Tipo de credencial no reconocido.']),
                };
                $limite = (int) ($vinculacion->cantidad_credenciales ?? $contrato->nro_credenciales ?? 0);
                $movimientos = DB::table('cred_adicionales')
                    ->where('feria', $idFeria)
                    ->where('id_empresa', $empresa->id_empresa)
                    ->when($idContrato, fn ($query) => $query->where('contrato', $idContrato)->whereNull('id_empresa_evento_credencial'), fn ($query) => $query->whereNull('contrato')->where('id_empresa_evento_credencial', $vinculacion->id))
                    ->get(['cant_adic', 'tipo_movimiento']);
                $limite += $movimientos->sum(fn ($movimiento) => $movimiento->tipo_movimiento === 'disminucion' ? -(int) $movimiento->cant_adic : (int) $movimiento->cant_adic);
                $usadas = DB::table('credenciales')->where('id_empresa', $empresa->id_empresa)->where('id_feria', $idFeria)->when($idContrato, fn ($query) => $query->where('id_contrato', $idContrato), fn ($query) => $query->whereNull('id_contrato'))->where('tipo_credencial', $tipoCodigo)->where('estado_credencial', '!=', 3)->count();
                if ($usadas >= $limite) {
                    throw ValidationException::withMessages(['credencial' => 'No quedan credenciales disponibles para este tipo.']);
                }

                $ci = trim($datos['ci']);
                $expositor = DB::table('expositores')->where('ci', $ci)->lockForUpdate()->first();
                if ($expositor) {
                    $yaAcreditado = DB::table('credenciales')->where('id_expositor', $expositor->id_exp)->where('id_feria', $idFeria)->where('estado_credencial', '!=', 3)->exists();
                    if ($yaAcreditado) {
                        throw ValidationException::withMessages(['ci' => 'Este expositor ya está acreditado en este evento.']);
                    }
                    $idExpositor = (int) $expositor->id_exp;
                    DB::table('expositores')->where('id_exp', $idExpositor)->update(['tipo_documento' => $datos['tipo_documento'], 'nombre' => trim($datos['nombre']), 'email' => $datos['email'] ?? null, 'celular' => $datos['celular'] ?? null, 'updated_at' => now()]);
                } else {
                    if (! $datos['foto']) {
                        throw ValidationException::withMessages(['foto' => 'La fotografía es obligatoria para un expositor nuevo.']);
                    }
                    $idExpositor = DB::table('expositores')->insertGetId(['ci' => $ci, 'tipo_documento' => $datos['tipo_documento'], 'nombre' => trim($datos['nombre']), 'email' => $datos['email'] ?? null, 'celular' => $datos['celular'] ?? null, 'estado' => true, 'created_at' => now(), 'updated_at' => now()]);
                }

                $rutaFoto = $expositor?->foto;
                if ($datos['foto']) {
                    $foto = $datos['foto'];
                    $nombreFoto = 'acreditado_'.Str::uuid().'.'.$foto->extension();
                    $rutaAlmacenada = Storage::disk('acreditados')->putFileAs('', $foto, $nombreFoto);
                    if (! $rutaAlmacenada) {
                        throw new \RuntimeException('No se pudo guardar la fotografía del acreditado.');
                    }
                    $fotoAlmacenada = $nombreFoto;
                    $rutaFoto = 'images/fotos/'.$nombreFoto;
                    DB::table('expositores')->where('id_exp', $idExpositor)->update(['foto' => $rutaFoto]);
                }

                $idCredencial = DB::table('credenciales')->insertGetId(['cod_credencial' => 'ACR-'.$idFeria.'-'.($idContrato ?? 'D').'-'.$idExpositor.'-'.now()->format('YmdHis'), 'id_expositor' => $idExpositor, 'id_contrato' => $idContrato, 'id_empresa' => $empresa->id_empresa, 'id_feria' => $idFeria, 'com_medio' => '', 'com_dias' => '', 'tipo_credencial' => $tipoCodigo, 'modalidad_credencial' => (int) ($vinculacion->tipo_credenciales ?? $contrato->tipo_credenciales ?? 1), 'estado_credencial' => 1, 'ingreso' => 0, 'created_at' => now(), 'updated_at' => now()]);

                return ['id_expositor' => $idExpositor, 'id_credencial' => $idCredencial, 'foto' => $rutaFoto];
            });
        } catch (\Throwable $exception) {
            if ($fotoAlmacenada) {
                try {
                    Storage::disk('acreditados')->delete($fotoAlmacenada);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        return response()->json(['success' => true, 'message' => 'Acreditado registrado correctamente.', 'data' => $resultado], 201);
    }

    public function descargarIndividual(int $idCredencial): BinaryFileResponse
    {
        $acreditado = $this->credencialAutorizada($idCredencial);
        if ((int) ($acreditado->modalidad_credencial ?? 1) !== 1) {
            abort(422, 'Solo se pueden descargar credenciales digitales.');
        }

        $contenido = $this->generarImagen($acreditado);
        $directorio = storage_path('app/credenciales');
        File::ensureDirectoryExists($directorio);
        $nombreArchivo = 'credencial-'.Str::slug($acreditado->nombre ?: (string) $idCredencial).'-'.Str::uuid().'.png';
        $ruta = $directorio.DIRECTORY_SEPARATOR.$nombreArchivo;
        File::put($ruta, $contenido);
        $this->registrarDescarga($idCredencial, 'png_individual');

        return response()->download($ruta, 'credencial-'.Str::slug($acreditado->nombre ?: (string) $idCredencial).'.png', [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) File::size($ruta),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ])->deleteFileAfterSend(true);
    }

    public function descargarArchivo(string $archivo): BinaryFileResponse
    {
        $ruta = $this->directorioDescargasEmpresa().DIRECTORY_SEPARATOR.basename($archivo);
        if (! File::exists($ruta) || ! str_ends_with(strtolower($archivo), '.zip')) {
            abort(404);
        }

        return response()->download($ruta, $archivo, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    public function descargarSeleccionadas(Request $request): JsonResponse|BinaryFileResponse
    {
        $datos = $request->validate([
            'credenciales' => ['required', 'array', 'min:1', 'max:100'],
            'credenciales.*' => ['integer', 'distinct'],
        ]);
        $acreditados = collect($datos['credenciales'])->map(fn (int $id) => $this->credencialAutorizada($id));
        if ($acreditados->contains(fn ($item) => (int) ($item->modalidad_credencial ?? 1) !== 1)) {
            abort(422, 'Solo se pueden descargar credenciales digitales.');
        }

        $directorio = $this->directorioDescargasEmpresa();
        File::ensureDirectoryExists($directorio);
        $this->limpiarDescargasTemporales($directorio);
        $ruta = $directorio.DIRECTORY_SEPARATOR.'credenciales-'.Str::uuid().'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'No se pudo preparar el archivo ZIP.');
        }

        $completado = false;
        try {
            $nombresUsados = [];
            foreach ($acreditados as $acreditado) {
                $baseNombre = 'credencial-'.Str::slug($acreditado->nombre ?: (string) $acreditado->id_credencial);
                $nombresUsados[$baseNombre] = ($nombresUsados[$baseNombre] ?? 0) + 1;
                $nombreImagen = $baseNombre.($nombresUsados[$baseNombre] > 1 ? '-'.$nombresUsados[$baseNombre] : '').'.png';
                if (! $zip->addFromString($nombreImagen, $this->generarImagen($acreditado))) {
                    throw new \RuntimeException("No se pudo agregar la credencial {$acreditado->id_credencial} al ZIP.");
                }
                $this->registrarDescarga($acreditado->id_credencial, 'png_lote');
            }
            if (! $zip->close()) {
                abort(500, 'No se pudo finalizar el archivo ZIP.');
            }
            $completado = true;
        } finally {
            if (! $completado) {
                $zip->close();
                File::delete($ruta);
            }
        }

        $nombreArchivo = 'credenciales-digitales-'.Str::slug($acreditados->first()->nombre_empresa ?: 'empresa').'.zip';
        if ($request->boolean('archivo')) {
            return response()->download($ruta, $nombreArchivo, [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) File::size($ruta),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ])->deleteFileAfterSend(true);
        }

        return response()->json([
            'success' => true,
            'download_url' => url('/acreditaciones/descargas/'.basename($ruta)),
            'filename' => $nombreArchivo,
        ]);
    }

    public function enviarSeleccionadas(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'credenciales' => ['required', 'array', 'min:1', 'max:100'],
            'credenciales.*' => ['integer', 'distinct'],
        ]);
        $acreditados = collect($datos['credenciales'])->map(fn (int $id) => $this->credencialAutorizada($id));
        $errores = [];
        $encoladas = 0;

        foreach ($acreditados as $acreditado) {
            if ((int) ($acreditado->modalidad_credencial ?? 1) !== 1) {
                $errores[] = "La credencial de {$acreditado->nombre} no es digital.";

                continue;
            }

            $correo = trim((string) ($acreditado->email ?: $acreditado->com_email));
            if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores[] = "El expositor {$acreditado->nombre} no tiene un correo válido.";

                continue;
            }

            try {
                $imagen = $this->generarImagen($acreditado);
                EnviarCredencialDigitalJob::dispatch(
                    (int) $acreditado->id_credencial,
                    $correo,
                    (string) $acreditado->nombre,
                    (string) $acreditado->nombre_feria,
                    base64_encode($imagen),
                );
                $encoladas++;
            } catch (\Throwable $exception) {
                report($exception);
                $errores[] = "No se pudo preparar la credencial de {$acreditado->nombre}.";
            }
        }

        if ($errores && $encoladas === 0) {
            return response()->json(['success' => false, 'message' => implode(' ', $errores)], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Se encolaron {$encoladas} credenciales digitales para envío por correo electrónico.".($errores ? ' Pendientes: '.implode(' ', $errores) : ''),
            'encoladas' => $encoladas,
            'pendientes' => $errores,
        ]);
    }

    private function limpiarDescargasTemporales(string $directorio): void
    {
        foreach (File::files($directorio) as $archivo) {
            if ($archivo->getMTime() < now()->subHour()->getTimestamp()) {
                File::delete($archivo->getPathname());
            }
        }
    }

    private function directorioDescargasEmpresa(): string
    {
        $empresa = Auth::guard('empresa')->user();
        abort_unless($empresa, 401);

        return storage_path('app/credenciales/'.(int) $empresa->id_empresa);
    }

    private function credencialAutorizada(int $id): object
    {
        $empresa = Auth::guard('empresa')->user();
        $acreditado = DB::table('credenciales as c')
            ->join('expositores as e', 'e.id_exp', '=', 'c.id_expositor')
            ->join('eventos as f', 'f.id_feria', '=', 'c.id_feria')
            ->where('c.id_credencial', $id)
            ->where('c.id_empresa', $empresa->id_empresa)
            ->where('c.estado_credencial', '!=', 3)
            ->select('c.*', 'e.nombre', 'e.ci', 'e.exp', 'e.foto', 'e.email', 'e.com_email', 'f.nombre_feria', 'empresa.nombre_empresa')
            ->leftJoin('empresas as empresa', 'empresa.id_empresa', '=', 'c.id_empresa')
            ->first();
        if (! $acreditado) {
            abort(404, 'La credencial no pertenece a la empresa autenticada.');
        }

        return $acreditado;
    }

    private function registrarDescarga(int $id, string $tipo): void
    {
        if (Schema::hasTable('credencial_digital_descargas')) {
            DB::table('credencial_digital_descargas')->insert(['id_credencial' => $id, 'tipo_descarga' => $tipo, 'created_at' => now()]);
        }
    }

    private function generarImagen(object $acreditado): string
    {
        $tipo = match ((int) $acreditado->tipo_credencial) {
            1 => 'Expositor', 2 => 'Prensa', 3 => 'Oficial', 4 => 'Servicios', 5 => 'Negocios', default => 'Expositor'
        };
        $diseno = DB::table('credencial_tipo_diseno')->where('id_feria', $acreditado->id_feria)->where('tipo_credencial', $tipo)->first();
        $rutaDiseno = base_path('../intranetfexco/public/'.($diseno->ruta ?? ''));
        if (! $diseno || ! File::exists($rutaDiseno)) {
            abort(422, 'No existe el diseño de credencial para este evento.');
        }

        $token = DB::table('credencial_qr_tokens')->where('id_credencial', $acreditado->id_credencial)->where('activo', true)->first();
        $valor = null;
        if ($token) {
            try {
                $valor = Crypt::decryptString($token->token_cifrado);
            } catch (\Throwable) {
                DB::table('credencial_qr_tokens')->where('id', $token->id)->update([
                    'activo' => false,
                    'revocado_at' => now(),
                    'motivo_revocacion' => 'token_no_recuperable_plataforma',
                    'updated_at' => now(),
                ]);
            }
        }
        if (! $valor) {
            $valor = 'crd_'.Str::lower(Str::random(48));
            $version = (int) DB::table('credencial_qr_tokens')->where('id_credencial', $acreditado->id_credencial)->max('version') + 1;
            DB::table('credencial_qr_tokens')->insert(['id_credencial' => $acreditado->id_credencial, 'token_hash' => hash('sha256', $valor), 'token_cifrado' => Crypt::encryptString($valor), 'version' => $version, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $archivosTemporales = [];
        $rutaQr = storage_path('app/credenciales-qr-'.$acreditado->id_credencial.'-'.Str::uuid().'.png');
        $opcionesQr = new QROptions;
        $opcionesQr->outputType = QROutputInterface::GDIMAGE_PNG;
        $opcionesQr->outputBase64 = false;
        $opcionesQr->scale = 8;
        set_error_handler(static fn () => true, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            File::put($rutaQr, (new QRCode($opcionesQr))->render($valor));
        } finally {
            restore_error_handler();
        }
        $archivosTemporales[] = $rutaQr;
        try {
            $fondo = @imagecreatefromstring((string) File::get($rutaDiseno));
            if (! $fondo) {
                throw new \RuntimeException('No se pudo cargar el diseño de credencial.');
            }

            $ancho = 856;
            $alto = 1333;
            $imagen = imagecreatetruecolor($ancho, $alto);
            imagealphablending($imagen, true);
            imagesavealpha($imagen, true);
            imagecopyresampled($imagen, $fondo, 0, 0, 0, 0, $ancho, $alto, imagesx($fondo), imagesy($fondo));
            imagedestroy($fondo);

            $rutaFoto = $this->crearFotoCircularImagen($acreditado->foto, $archivosTemporales);
            if ($rutaFoto && ($foto = @imagecreatefrompng($rutaFoto))) {
                imagecopyresampled($imagen, $foto, 268, 200, 0, 0, 320, 320, imagesx($foto), imagesy($foto));
                imagedestroy($foto);
            }

            $blanco = imagecolorallocate($imagen, 255, 255, 255);
            $nombrePdf = $this->normalizarNombre((string) $acreditado->nombre);
            $this->dibujarTextoImagen($imagen, $nombrePdf, 80, 570, 696, 60, $blanco, true);
            $this->dibujarTextoImagen($imagen, 'CI: '.trim($acreditado->ci.' '.$acreditado->exp), 80, 635, 696, 44, $blanco, false);

            $qr = @imagecreatefrompng($rutaQr);
            if (! $qr) {
                throw new \RuntimeException('No se pudo cargar el código QR generado.');
            }
            imagecopyresampled($imagen, $qr, 203, 760, 0, 0, 450, 450, imagesx($qr), imagesy($qr));
            imagedestroy($qr);

            ob_start();
            imagepng($imagen, null, 6);

            return (string) ob_get_clean();
        } finally {
            if (isset($imagen) && $imagen instanceof \GdImage) {
                imagedestroy($imagen);
            }
            foreach ($archivosTemporales as $archivo) {
                File::delete($archivo);
            }
        }
    }

    private function dibujarTextoImagen(\GdImage $imagen, ?string $texto, int $x, int $y, int $ancho, int $alto, int $color, bool $negrita): void
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return;
        }

        $fuente = $this->fuenteImagen($negrita);
        if (! $fuente || ! function_exists('imagettftext')) {
            $borde = imagecolorallocate($imagen, 0, 0, 0);
            foreach ([[-1, -1], [1, -1], [-1, 1], [1, 1]] as [$dx, $dy]) {
                imagestring($imagen, 5, $x + $dx, $y + $dy, $texto, $borde);
            }
            imagestring($imagen, 5, $x, $y, $texto, $color);

            return;
        }

        $tamano = $negrita ? 42 : 28;
        do {
            $caja = imagettfbbox($tamano, 0, $fuente, $texto);
            $anchoTexto = abs($caja[2] - $caja[0]);
            $tamano--;
        } while ($anchoTexto > $ancho && $tamano > 10);

        $caja = imagettfbbox($tamano, 0, $fuente, $texto);
        $anchoTexto = abs($caja[2] - $caja[0]);
        $altoTexto = abs($caja[7] - $caja[1]);
        $posicionX = $x + max(0, intdiv($ancho - $anchoTexto, 2));
        $posicionY = $y + max($altoTexto, intdiv($alto + $altoTexto, 2));
        $borde = imagecolorallocate($imagen, 0, 0, 0);
        foreach ([[-2, -2], [0, -2], [2, -2], [-2, 0], [2, 0], [-2, 2], [0, 2], [2, 2]] as [$dx, $dy]) {
            imagettftext($imagen, $tamano, 0, $posicionX + $dx, $posicionY + $dy, $borde, $fuente, $texto);
        }
        imagettftext($imagen, $tamano, 0, $posicionX, $posicionY, $color, $fuente, $texto);
    }

    private function fuenteImagen(bool $negrita): ?string
    {
        $rutas = $negrita
            ? [public_path('fonts/DejaVuSans-Bold.ttf'), '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/System/Library/Fonts/Supplemental/Arial Bold.ttf']
            : [public_path('fonts/DejaVuSans.ttf'), '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/System/Library/Fonts/Supplemental/Arial.ttf'];

        foreach ($rutas as $ruta) {
            if (File::exists($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    private function normalizarNombre(string $nombre): string
    {
        $nombre = preg_replace('/\s+/u', ' ', trim($nombre)) ?: '';

        return mb_convert_case(mb_strtolower($nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    private function crearFotoCircularImagen(?string $foto, array &$temporales): ?string
    {
        $ruta = $foto ? base_path('../intranetfexco/public/'.ltrim($foto, '/')) : null;
        if (! $ruta || ! File::exists($ruta) || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $origen = @imagecreatefromstring((string) file_get_contents($ruta));
        if (! $origen) {
            return null;
        }
        $anchoOrigen = imagesx($origen);
        $altoOrigen = imagesy($origen);
        $ladoOrigen = min($anchoOrigen, $altoOrigen);
        $lado = min($ladoOrigen, 320);
        $recorte = imagecreatetruecolor($lado, $lado);
        imagecopyresampled($recorte, $origen, 0, 0, intdiv($anchoOrigen - $ladoOrigen, 2), intdiv($altoOrigen - $ladoOrigen, 2), $lado, $lado, $ladoOrigen, $ladoOrigen);
        $circulo = imagecreatetruecolor($lado, $lado);
        imagealphablending($circulo, false);
        imagesavealpha($circulo, true);
        imagefill($circulo, 0, 0, imagecolorallocatealpha($circulo, 0, 0, 0, 127));
        $radio = $lado / 2;
        for ($x = 0; $x < $lado; $x++) {
            for ($y = 0; $y < $lado; $y++) {
                if ((($x - $radio) ** 2) + (($y - $radio) ** 2) <= $radio ** 2) {
                    imagesetpixel($circulo, $x, $y, imagecolorat($recorte, $x, $y));
                }
            }
        }
        $temporal = storage_path('app/credenciales-foto-'.Str::uuid().'.png');
        imagepng($circulo, $temporal);
        $temporales[] = $temporal;
        imagedestroy($origen);
        imagedestroy($recorte);
        imagedestroy($circulo);

        return $temporal;
    }
}
