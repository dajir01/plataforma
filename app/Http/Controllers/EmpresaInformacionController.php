<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmpresaInformacionController extends Controller
{
    public function show(Request $request)
    {
        $empresa = $request->user('empresa');
        abort_unless($empresa instanceof Empresa, 401);

        $informacion = $empresa->only([
            'nombre_empresa',
            'nit',
            'direccion',
            'telefono',
            'fax',
            'email',
            'web',
            'pais',
            'ciudad',
            'aniversario',
            'nr_escritura',
            'fecha_nr_escritura',
            'matricula',
            'nr_poder',
            'nr_notaria',
            'nr_notario',
            'fecha_nr_poder',
            'distrito',
            'registro_senag',
            'certi_adi',
            'ven_anual',
            'expor_anual',
            'tam_comp',
            'desc_producto',
            'nombre_gerente',
            'ci_gerente',
            'exp_ci_gerente',
            'fono_gerente',
            'cargo_gerente',
            'nombre_responsable',
            'telefono_responsable',
            'email_representante',
            'otro_rubro',
            'paises_representante',
            'empresa_representante',
            'marcas_representante',
        ]);

        $informacion['pais_nombre'] = $this->nombreRelacionado('paises', 'id', 'nombre', $empresa->pais);
        $informacion['ciudad_nombre'] = $this->nombreRelacionado('localidades', 'id', 'nombre', $empresa->ciudad);
        $informacion['rubro_nombre'] = $this->nombreRelacionado('rubros', 'id_rubro', 'nombre_rubro', $empresa->rubro);
        $informacion['subrubro_nombre'] = $this->nombreRelacionado('subrubros', 'id_subrubro', 'nombre_subrubro', $empresa->subrubro);
        $informacion['categoria_nombre'] = [
            '1' => 'Grande',
            '2' => 'Mediana',
            '3' => 'Pequeña',
            '4' => 'Artesano',
        ][(string) $empresa->cluster] ?? null;
        $informacion['actividad_principal_nombre'] = $this->actividadPrincipal($empresa->id_tipo_representante);

        $standsPorContrato = [];
        $contratos = DB::table('contrato as c')
            ->join('eventos as e', 'e.id_feria', '=', 'c.id_feria')
            ->where('c.id_empresa', $empresa->id_empresa)
            ->where('c.codigo_contrato', '>', 0)
            ->where('c.total_stands', '>', 0)
            ->orderByDesc('e.fecha_inicio')
            ->get([
                'c.id_contrato',
                'c.codigo_contrato',
                'c.id_feria',
                'c.total_stands',
                'c.metraje_total',
                'c.nro_credenciales',
                'c.tipo_credenciales',
                'c.stand_1',
                'c.stand_2',
                'c.stand_3',
                'c.stand_4',
                'c.stand_5',
                'c.stand_6',
                'c.stand_7',
                'c.stand_8',
                'c.stand_9',
                'c.stand_10',
                'c.stand_11',
                'c.stand_12',
                'e.nombre_feria',
                'e.fecha_inicio',
                'e.fecha_fin',
            ]);

        $idsStand = $contratos
            ->flatMap(fn ($contrato) => collect(range(1, 12))->map(fn ($numero) => (int) $contrato->{'stand_'.$numero}))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($idsStand->isNotEmpty()) {
            $standsPorContrato = DB::table('stands')
                ->whereIn('id_stand', $idsStand)
                ->pluck('numero_stand', 'id_stand')
                ->all();
        }

        $participaciones = $contratos->map(function ($contrato) use ($standsPorContrato) {
            $stands = collect(range(1, 12))
                ->map(fn ($numero) => $standsPorContrato[(int) $contrato->{'stand_'.$numero}] ?? null)
                ->filter()
                ->values();

            return [
                'evento' => $contrato->nombre_feria,
                'fecha_inicio' => $contrato->fecha_inicio,
                'fecha_fin' => $contrato->fecha_fin,
                'codigo_contrato' => $contrato->codigo_contrato,
                'cantidad_stands' => $contrato->total_stands,
                'stands' => $stands,
                'metraje_total' => $contrato->metraje_total,
                'cantidad_credenciales' => $contrato->nro_credenciales,
                'tipo_credenciales' => match ((int) $contrato->tipo_credenciales) {
                    1 => 'Digitales',
                    2 => 'Físicas',
                    default => null,
                },
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'empresa' => $informacion,
                'participaciones' => $participaciones,
            ],
        ]);
    }

    private function nombreRelacionado(string $tabla, string $columnaId, string $columnaNombre, mixed $id): ?string
    {
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return null;
        }

        $nombre = DB::table($tabla)->where($columnaId, $id)->value($columnaNombre);

        return $nombre === null ? null : (string) $nombre;
    }

    private function actividadPrincipal(mixed $idTipoRepresentante): ?string
    {
        if ($idTipoRepresentante === null || $idTipoRepresentante === '') {
            return null;
        }

        $codigo = explode('00', (string) $idTipoRepresentante)[0];

        return [
            '1' => 'Industria Manufacturera',
            '2' => 'Comercio',
            '3' => 'Servicios',
        ][$codigo] ?? null;
    }
}
