<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Feria;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReglamentoExpositorController extends Controller
{
    public function eventos(): JsonResponse
    {
        $empresa = $this->empresaAutenticada();
        $eventos = $this->eventosConContrato($empresa)
            ->select('eventos.id_feria', 'eventos.nombre_feria', 'eventos.fecha_inicio', 'eventos.fecha_fin')
            ->orderByRaw('COALESCE(eventos.fecha_inicio, eventos.inicio) ASC')
            ->orderBy('eventos.id_feria')
            ->get();

        return response()->json(['success' => true, 'data' => $eventos]);
    }

    public function index(int $idFeria): JsonResponse
    {
        $empresa = $this->empresaAutenticada();
        abort_unless($this->tieneAccesoAlEvento($empresa, $idFeria), 404);

        $reglamentos = DB::table('reglamentos_feria')
            ->where('id_feria', $idFeria)
            ->orderByDesc('created_at')
            ->get(['id_reglamento', 'nombre_reglamento', 'descripcion', 'nombre_archivo', 'created_at']);

        return response()->json(['success' => true, 'data' => $reglamentos]);
    }

    public function descargar(int $idReglamento): BinaryFileResponse
    {
        $empresa = $this->empresaAutenticada();
        $reglamento = DB::table('reglamentos_feria')
            ->where('id_reglamento', $idReglamento)
            ->first(['id_reglamento', 'id_feria', 'nombre_archivo', 'ruta_archivo']);

        abort_unless($reglamento, 404);
        abort_unless($this->tieneAccesoAlEvento($empresa, (int) $reglamento->id_feria), 404);

        $path = $this->rutaArchivoPrivada((string) $reglamento->ruta_archivo);
        abort_unless($path, 404);

        return response()->download($path, basename((string) $reglamento->nombre_archivo), [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function empresaAutenticada(): Empresa
    {
        $empresa = Auth::guard('empresa')->user();
        abort_unless($empresa instanceof Empresa, 401);

        return $empresa;
    }

    private function eventosConContrato(Empresa $empresa)
    {
        return Feria::query()
            ->where(function ($query) {
                $query->whereNull('eventos.fecha_fin')
                    ->orWhereDate('eventos.fecha_fin', '>=', today());
            })
            ->whereExists(function ($query) use ($empresa) {
                $query->selectRaw('1')
                    ->from('contrato')
                    ->whereColumn('contrato.id_feria', 'eventos.id_feria')
                    ->where('contrato.id_empresa', $empresa->id_empresa)
                    ->where('contrato.codigo_contrato', '>', 0)
                    ->where('contrato.total_stands', '>', 0);
            });
    }

    private function tieneAccesoAlEvento(Empresa $empresa, int $idFeria): bool
    {
        return $this->eventosConContrato($empresa)
            ->where('eventos.id_feria', $idFeria)
            ->exists();
    }

    private function rutaArchivoPrivada(string $rutaArchivo): ?string
    {
        $ruta = str_replace('\\', '/', ltrim($rutaArchivo, '/'));
        $segmentos = explode('/', $ruta);
        if (! str_starts_with($ruta, 'reglamentos-feria/') || in_array('..', $segmentos, true) || strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) !== 'pdf') {
            return null;
        }

        $directorioBase = config('reglamentos.private_root');
        $raiz = realpath($directorioBase);
        $archivo = realpath(rtrim($directorioBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$ruta);
        if ($raiz && $archivo && str_starts_with($archivo, $raiz.DIRECTORY_SEPARATOR) && is_file($archivo)) {
            return $archivo;
        }

        return null;
    }
}
