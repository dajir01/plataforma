<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Empresa extends Authenticatable
{
    protected $table = 'empresas';
    protected $primaryKey = 'id_empresa';

    protected $hidden = [
        'pass',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'es_activo' => 'boolean',
        ];
    }

    /**
     * La contraseña de la empresa se almacena en la columna `pass`.
     */
    public function getAuthPassword()
    {
        return $this->pass;
    }

    public function getAuthPasswordName()
    {
        return 'pass';
    }
}
