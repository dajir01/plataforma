<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Feria;
use Illuminate\Database\Eloquent\Builder;

class EmpresaEventoAccess
{
    public function eventosDisponibles(Empresa $empresa): Builder
    {
        return Feria::query()
            ->whereNotNull('eventos.tipo_credenciales')
            ->where('eventos.tipo_credenciales', '!=', '')
            ->where(function ($query) {
                $query->whereNull('eventos.fecha_fin')
                    ->orWhereDate('eventos.fecha_fin', '>=', today());
            })
            ->where(function ($query) use ($empresa) {
                $query->whereExists(function ($subquery) use ($empresa) {
                    $subquery->selectRaw('1')
                        ->from('contrato')
                        ->whereColumn('contrato.id_feria', 'eventos.id_feria')
                        ->where('contrato.id_empresa', $empresa->id_empresa)
                        ->where('contrato.codigo_contrato', '>', 0)
                        ->where('contrato.total_stands', '>', 0);
                })->orWhereExists(function ($subquery) use ($empresa) {
                    $subquery->selectRaw('1')
                        ->from('empresa_evento_credenciales')
                        ->whereColumn('empresa_evento_credenciales.id_feria', 'eventos.id_feria')
                        ->where('empresa_evento_credenciales.id_empresa', $empresa->id_empresa)
                        ->where('empresa_evento_credenciales.estado', true);
                });
            });
    }

    public function puedeAcceder(Empresa $empresa, int $idFeria): bool
    {
        return $this->eventosDisponibles($empresa)
            ->where('eventos.id_feria', $idFeria)
            ->exists();
    }
}
