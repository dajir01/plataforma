<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcreditacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
            'cache.default' => 'array',
            'filesystems.disks.acreditados.root' => storage_path('framework/testing/acreditados-'.uniqid()),
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('empresas', function (Blueprint $table) {
            $table->increments('id_empresa');
            $table->string('nit')->nullable();
            $table->string('usuario')->nullable();
            $table->string('pass')->nullable();
            $table->string('nombre_empresa')->nullable();
            $table->boolean('es_activo')->nullable();
        });
        Schema::create('eventos', function (Blueprint $table) {
            $table->increments('id_feria');
            $table->string('nombre_feria');
            $table->string('codigo_contrato')->nullable();
            $table->string('tipo_credenciales')->nullable();
            $table->date('inicio')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
        });
        Schema::create('contrato', function (Blueprint $table) {
            $table->increments('id_contrato');
            $table->unsignedInteger('id_feria');
            $table->unsignedInteger('id_empresa');
            $table->integer('codigo_contrato');
            $table->integer('total_stands');
            $table->integer('nro_credenciales')->default(0);
            $table->integer('tipo_credenciales')->nullable();
            $table->unsignedInteger('stand_1')->nullable();
            $table->decimal('metraje_total', 8, 2)->nullable();
        });
        Schema::create('empresa_evento_credenciales', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_empresa');
            $table->unsignedInteger('id_feria');
            $table->unsignedInteger('id_contrato')->nullable();
            $table->string('tipo_credencial');
            $table->integer('tipo_credenciales')->nullable();
            $table->integer('cantidad_credenciales');
            $table->boolean('estado');
        });
        Schema::create('cred_adicionales', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('feria');
            $table->unsignedInteger('id_empresa');
            $table->unsignedInteger('contrato')->nullable();
            $table->unsignedInteger('id_empresa_evento_credencial')->nullable();
            $table->integer('cant_adic');
            $table->string('tipo_movimiento');
        });
        Schema::create('expositores', function (Blueprint $table) {
            $table->increments('id_exp');
            $table->string('ci')->unique();
            $table->string('tipo_documento')->nullable();
            $table->string('nombre')->nullable();
            $table->string('email')->nullable();
            $table->string('com_email')->nullable();
            $table->string('celular')->nullable();
            $table->string('exp')->nullable();
            $table->string('foto')->nullable();
            $table->boolean('estado')->nullable();
            $table->timestamps();
        });
        Schema::create('credenciales', function (Blueprint $table) {
            $table->increments('id_credencial');
            $table->string('cod_credencial');
            $table->unsignedInteger('id_expositor');
            $table->unsignedInteger('id_contrato')->nullable();
            $table->unsignedInteger('id_empresa');
            $table->unsignedInteger('id_feria');
            $table->string('com_medio')->nullable();
            $table->string('com_dias')->nullable();
            $table->integer('tipo_credencial');
            $table->integer('modalidad_credencial')->nullable();
            $table->integer('estado_credencial');
            $table->integer('ingreso')->nullable();
            $table->timestamps();
        });
        Schema::create('stands', function (Blueprint $table) {
            $table->increments('id_stand');
            $table->unsignedInteger('id_pabellon')->nullable();
            $table->string('numero_stand')->nullable();
        });
        Schema::create('pabellones', function (Blueprint $table) {
            $table->increments('id_pabellon');
            $table->string('nombre_pabellon')->nullable();
        });

        DB::table('empresas')->insert([
            'id_empresa' => 1,
            'nombre_empresa' => 'Empresa de prueba',
        ]);
        DB::table('eventos')->insert([
            'id_feria' => 10,
            'nombre_feria' => 'Feria de prueba',
            'codigo_contrato' => 'FEX',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
        ]);
        DB::table('empresa_evento_credenciales')->insert([
            [
                'id' => 1,
                'id_empresa' => 1,
                'id_feria' => 10,
                'tipo_credencial' => 'Expositor',
                'tipo_credenciales' => 2,
                'cantidad_credenciales' => 2,
                'estado' => true,
            ],
            [
                'id' => 2,
                'id_empresa' => 1,
                'id_feria' => 10,
                'tipo_credencial' => 'Expositor',
                'tipo_credenciales' => 1,
                'cantidad_credenciales' => 2,
                'estado' => true,
            ],
        ]);
        DB::table('cred_adicionales')->insert([
            'feria' => 10,
            'id_empresa' => 1,
            'id_empresa_evento_credencial' => 2,
            'cant_adic' => 1,
            'tipo_movimiento' => 'disminucion',
        ]);
        DB::table('expositores')->insert([
            [
                'id_exp' => 1,
                'ci' => '12345',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Persona de prueba',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id_exp' => 2,
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Otra persona',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('credenciales')->insert([
            'id_credencial' => 1,
            'cod_credencial' => 'ACR-10-1',
            'id_expositor' => 1,
            'id_empresa' => 1,
            'id_feria' => 10,
            'tipo_credencial' => 1,
            'modalidad_credencial' => 2,
            'estado_credencial' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_summary_counts_direct_credentials_once_and_applies_direct_decreases(): void
    {
        $response = $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/acreditaciones/eventos/10/resumen');

        $response->assertOk()
            ->assertJsonPath('data.tipos.0.asignadas', 3)
            ->assertJsonPath('data.tipos.0.utilizadas', 1)
            ->assertJsonPath('data.tipos.0.disponibles', 2)
            ->assertJsonPath('data.tipos.0.registros.0.modalidad_credencial', 'Física')
            ->assertJsonPath('data.tipos.0.registros.0.credenciales_utilizadas', 1)
            ->assertJsonPath('data.tipos.0.registros.0.credenciales_disponibles', 1)
            ->assertJsonPath('data.tipos.0.registros.1.credenciales_utilizadas', 0)
            ->assertJsonPath('data.tipos.0.registros.1.credenciales_disponibles', 1)
            ->assertJsonCount(1, 'data.tipos.0.registros.0.acreditados')
            ->assertJsonCount(0, 'data.tipos.0.registros.1.acreditados');
    }

    public function test_accreditation_uses_remaining_capacity_across_direct_assignments_of_same_type(): void
    {
        $response = $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->post('/acreditaciones/eventos/10/acreditados', [
                'id_vinculacion' => 2,
                'tipo_credencial' => 'Expositor',
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Otra persona',
            ]);

        $response->assertCreated();
        $this->assertSame(2, DB::table('credenciales')->where('id_feria', 10)->count());
    }

    public function test_event_19_forces_all_contract_credentials_to_digital(): void
    {
        DB::table('eventos')->insert([
            'id_feria' => 19,
            'nombre_feria' => 'Evento 19',
            'codigo_contrato' => 'FEX',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
        ]);
        DB::table('contrato')->insert([
            [
                'id_contrato' => 19,
                'id_feria' => 19,
                'id_empresa' => 1,
                'codigo_contrato' => 19,
                'total_stands' => 1,
                'nro_credenciales' => 2,
                'tipo_credenciales' => 2,
            ],
            [
                'id_contrato' => 20,
                'id_feria' => 19,
                'id_empresa' => 1,
                'codigo_contrato' => 20,
                'total_stands' => 1,
                'nro_credenciales' => 2,
                'tipo_credenciales' => 2,
            ],
        ]);
        DB::table('credenciales')->insert([
            'id_credencial' => 2,
            'cod_credencial' => 'ACR-19-19',
            'id_expositor' => 1,
            'id_contrato' => 19,
            'id_empresa' => 1,
            'id_feria' => 19,
            'tipo_credencial' => 1,
            'modalidad_credencial' => 2,
            'estado_credencial' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $empresa = Empresa::query()->findOrFail(1);
        $this->actingAs($empresa, 'empresa')
            ->getJson('/acreditaciones/eventos/19/resumen')
            ->assertOk()
            ->assertJsonPath('data.tipos.0.registros.0.modalidad_credencial', 'Digital')
            ->assertJsonPath('data.tipos.0.registros.0.acreditados.0.modalidad_credencial', 'Digital')
            ->assertJsonPath('data.tipos.0.registros.1.modalidad_credencial', 'Digital');

        $this->actingAs($empresa, 'empresa')
            ->post('/acreditaciones/eventos/19/acreditados', [
                'id_contrato' => 20,
                'tipo_credencial' => 'Expositor',
                'ci' => '67890',
                'tipo_documento' => 'Cédula de identidad',
                'nombre' => 'Otra persona',
            ])
            ->assertCreated();

        $this->assertSame(
            1,
            (int) DB::table('credenciales')->where('id_feria', 19)->where('id_contrato', 20)->value('modalidad_credencial')
        );
    }
}
