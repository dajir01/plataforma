<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RegistrarDescargaCredencial
{
    public function registrar(
        int $idCredencial,
        string $tipo,
        ?int $idUsuario = null,
        ?int $idEmpresa = null,
        ?string $usuarioEmpresa = null
    ): void {
        if (! Schema::hasTable('credencial_digital_descargas')) {
            return;
        }

        DB::table('credencial_digital_descargas')->insert([
            'id_credencial' => $idCredencial,
            'id_usuario' => $idUsuario,
            'id_empresa' => $idEmpresa,
            'usuario_empresa' => $usuarioEmpresa,
            'tipo_descarga' => $tipo,
            'created_at' => now(),
        ]);
    }
}
