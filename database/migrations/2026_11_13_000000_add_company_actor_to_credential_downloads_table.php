<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credencial_digital_descargas', function (Blueprint $table) {
            $table->bigInteger('id_empresa')->nullable();
            $table->text('usuario_empresa')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credencial_digital_descargas', function (Blueprint $table) {
            $table->dropColumn(['id_empresa', 'usuario_empresa']);
        });
    }
};
