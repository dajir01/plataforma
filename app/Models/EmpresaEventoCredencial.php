<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaEventoCredencial extends Model
{
    protected $table = 'empresa_evento_credenciales';

    protected $casts = [
        'cantidad_credenciales' => 'integer',
        'estado' => 'boolean',
    ];
}
