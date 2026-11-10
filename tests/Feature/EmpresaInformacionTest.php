<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmpresaInformacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('empresas', function (Blueprint $table) {
            $table->increments('id_empresa');
            $table->string('nombre_empresa')->nullable();
            $table->string('nit')->nullable();
            $table->string('direccion')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('pais')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('rubro')->nullable();
            $table->string('subrubro')->nullable();
            $table->string('cluster')->nullable();
            $table->string('id_tipo_representante')->nullable();
            $table->string('nombre_gerente')->nullable();
            $table->string('nombre_responsable')->nullable();
            $table->string('ci_responsable')->nullable();
            $table->string('telefono_responsable')->nullable();
            $table->string('email_representante')->nullable();
            $table->string('usuario')->nullable();
            $table->string('pass')->nullable();
        });
        Schema::create('contrato', function (Blueprint $table) {
            $table->increments('id_contrato');
            $table->unsignedInteger('id_empresa');
            $table->unsignedInteger('id_feria');
            $table->integer('codigo_contrato');
            $table->integer('total_stands');
            $table->decimal('metraje_total', 10, 2);
            $table->integer('nro_credenciales');
            $table->integer('tipo_credenciales')->nullable();
            for ($stand = 1; $stand <= 12; $stand++) {
                $table->unsignedInteger("stand_{$stand}")->default(0);
            }
        });
        Schema::create('eventos', function (Blueprint $table) {
            $table->increments('id_feria');
            $table->string('nombre_feria');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
        });
        Schema::create('stands', function (Blueprint $table) {
            $table->increments('id_stand');
            $table->string('numero_stand');
        });
        Schema::create('paises', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nombre');
        });
        Schema::create('localidades', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nombre');
        });
        Schema::create('rubros', function (Blueprint $table) {
            $table->increments('id_rubro');
            $table->string('nombre_rubro');
        });
        Schema::create('subrubros', function (Blueprint $table) {
            $table->increments('id_subrubro');
            $table->string('nombre_subrubro');
        });

        DB::table('empresas')->insert([
            'id_empresa' => 1,
            'nombre_empresa' => 'Empresa uno',
            'nit' => '123456',
            'direccion' => 'Av. Principal 123',
            'telefono' => '555-0100',
            'email' => 'contacto@empresa.test',
            'pais' => '1',
            'ciudad' => '1',
            'rubro' => '1',
            'subrubro' => '1',
            'cluster' => '2',
            'id_tipo_representante' => '2000',
            'nombre_gerente' => 'Representante Uno',
            'nombre_responsable' => 'Contacto Uno',
            'ci_responsable' => 'CI-OCULTO',
            'telefono_responsable' => '555-0199',
            'email_representante' => 'representante@empresa.test',
            'usuario' => 'usuario-interno',
            'pass' => Hash::make('clave-privada'),
        ]);
        DB::table('empresas')->insert([
            'id_empresa' => 2,
            'nombre_empresa' => 'Empresa dos',
            'nit' => '654321',
            'usuario' => 'otro-usuario',
            'pass' => Hash::make('otra-clave'),
        ]);
        DB::table('paises')->insert(['id' => 1, 'nombre' => 'Bolivia']);
        DB::table('localidades')->insert(['id' => 1, 'nombre' => 'Cochabamba']);
        DB::table('rubros')->insert(['id_rubro' => 1, 'nombre_rubro' => 'Industria']);
        DB::table('subrubros')->insert(['id_subrubro' => 1, 'nombre_subrubro' => 'Alimentos']);
        DB::table('eventos')->insert([
            ['id_feria' => 10, 'nombre_feria' => 'Feria propia', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-10'],
            ['id_feria' => 20, 'nombre_feria' => 'Feria ajena', 'fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-10'],
        ]);
        DB::table('stands')->insert([
            ['id_stand' => 101, 'numero_stand' => 'A-101'],
            ['id_stand' => 201, 'numero_stand' => 'B-201'],
        ]);
        DB::table('contrato')->insert([
            [
                'id_empresa' => 1,
                'id_feria' => 10,
                'codigo_contrato' => 345,
                'total_stands' => 1,
                'metraje_total' => 12.5,
                'nro_credenciales' => 4,
                'tipo_credenciales' => 1,
                'stand_1' => 101,
            ],
            [
                'id_empresa' => 2,
                'id_feria' => 20,
                'codigo_contrato' => 456,
                'total_stands' => 1,
                'metraje_total' => 20,
                'nro_credenciales' => 8,
                'tipo_credenciales' => 2,
                'stand_1' => 201,
            ],
        ]);
    }

    public function test_company_can_only_read_its_profile_and_participations_without_credentials(): void
    {
        $response = $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/empresa/informacion');

        $response->assertOk()
            ->assertJsonPath('data.empresa.nombre_empresa', 'Empresa uno')
            ->assertJsonPath('data.empresa.nit', '123456')
            ->assertJsonPath('data.empresa.pais_nombre', 'Bolivia')
            ->assertJsonPath('data.empresa.ciudad_nombre', 'Cochabamba')
            ->assertJsonPath('data.empresa.rubro_nombre', 'Industria')
            ->assertJsonPath('data.empresa.subrubro_nombre', 'Alimentos')
            ->assertJsonPath('data.empresa.categoria_nombre', 'Mediana')
            ->assertJsonPath('data.empresa.actividad_principal_nombre', 'Comercio')
            ->assertJsonPath('data.empresa.telefono_responsable', '555-0199')
            ->assertJsonCount(1, 'data.participaciones')
            ->assertJsonPath('data.participaciones.0.evento', 'Feria propia')
            ->assertJsonPath('data.participaciones.0.codigo_contrato', 345)
            ->assertJsonPath('data.participaciones.0.stands.0', 'A-101')
            ->assertJsonMissingPath('data.empresa.ci_responsable')
            ->assertJsonMissingPath('data.empresa.usuario')
            ->assertJsonMissingPath('data.empresa.pass')
            ->assertJsonMissingPath('data.participaciones.0.precio_total');
    }

    public function test_company_information_is_not_available_without_authentication(): void
    {
        $this->getJson('/empresa/informacion')->assertUnauthorized();
    }

    public function test_company_information_has_no_write_endpoint(): void
    {
        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->postJson('/empresa/informacion', ['nombre_empresa' => 'Nombre modificado'])
            ->assertMethodNotAllowed();

        $this->assertSame('Empresa uno', DB::table('empresas')->where('id_empresa', 1)->value('nombre_empresa'));
    }
}
