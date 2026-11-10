<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feria extends Model
{
    protected $table = 'eventos';
    protected $primaryKey = 'id_feria';
    public $timestamps = false;
}
