<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReglamentoExpositorTest extends TestCase
{
    private string $reglamentosRoot;

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
            $table->string('nit')->nullable();
            $table->string('usuario')->nullable();
            $table->string('pass');
            $table->string('nombre_empresa')->nullable();
            $table->boolean('es_activo')->nullable();
        });
        Schema::create('eventos', function (Blueprint $table) {
            $table->increments('id_feria');
            $table->string('nombre_feria');
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
        });
        Schema::create('reglamentos_feria', function (Blueprint $table) {
            $table->increments('id_reglamento');
            $table->unsignedInteger('id_feria');
            $table->string('nombre_archivo');
            $table->string('ruta_archivo');
            $table->string('nombre_reglamento')->nullable();
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        DB::table('empresas')->insert([
            ['id_empresa' => 1, 'nit' => '111', 'usuario' => 'empresa-uno', 'pass' => Hash::make('password')],
            ['id_empresa' => 2, 'nit' => '222', 'usuario' => 'empresa-dos', 'pass' => Hash::make('password')],
        ]);

        $this->reglamentosRoot = storage_path('framework/testing/reglamentos-'.uniqid());
        File::ensureDirectoryExists($this->reglamentosRoot);
        config([
            'reglamentos.private_root' => $this->reglamentosRoot.'/private',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->reglamentosRoot);

        parent::tearDown();
    }

    public function test_it_lists_only_upcoming_events_with_a_valid_company_contract(): void
    {
        $this->crearEvento(10, 'Evento próximo', today()->addDays(30)->toDateString());
        $this->crearEvento(11, 'Evento vencido', today()->subDay()->toDateString());
        $this->crearEvento(12, 'Evento de otra empresa', today()->addDays(20)->toDateString());
        $this->crearEvento(13, 'Evento sin contrato válido', today()->addDays(40)->toDateString());
        $this->crearContrato(10, 1);
        $this->crearContrato(11, 1);
        $this->crearContrato(12, 2);
        $this->crearContrato(13, 1, codigo: 0);

        $response = $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/reglamentos-expositor/eventos');

        $response->assertOk()
            ->assertJsonPath('data.0.id_feria', 10)
            ->assertJsonCount(1, 'data');
    }

    public function test_company_can_list_and_download_regulations_from_private_storage(): void
    {
        $this->crearEvento(10, 'Feria próxima', today()->addDays(30)->toDateString());
        $this->crearContrato(10, 1);
        DB::table('reglamentos_feria')->insert([
            'id_reglamento' => 1,
            'id_feria' => 10,
            'nombre_archivo' => 'reglamento-general.pdf',
            'ruta_archivo' => 'reglamentos-feria/10/reglamento-general.pdf',
            'nombre_reglamento' => 'Reglamento General',
            'descripcion' => 'Normas para expositores.',
        ]);
        $ruta = $this->reglamentosRoot.'/private/reglamentos-feria/10/reglamento-general.pdf';
        File::ensureDirectoryExists(dirname($ruta));
        File::put($ruta, '%PDF-1.4 reglamento de prueba');

        $empresa = Empresa::query()->findOrFail(1);
        $this->actingAs($empresa, 'empresa')
            ->getJson('/reglamentos-expositor/eventos/10')
            ->assertOk()
            ->assertJsonPath('data.0.nombre_reglamento', 'Reglamento General');

        $this->actingAs($empresa, 'empresa')
            ->get('/reglamentos-expositor/1/descargar')
            ->assertOk()
            ->assertDownload('reglamento-general.pdf');
    }

    public function test_it_does_not_serve_a_legacy_public_copy(): void
    {
        $this->crearEvento(10, 'Feria próxima', today()->addDays(30)->toDateString());
        $this->crearContrato(10, 1);
        DB::table('reglamentos_feria')->insert([
            'id_reglamento' => 2,
            'id_feria' => 10,
            'nombre_archivo' => 'anexo.pdf',
            'ruta_archivo' => 'reglamentos-feria/10/anexo.pdf',
            'nombre_reglamento' => 'Anexo',
            'descripcion' => 'Anexo de reglamento.',
        ]);
        $ruta = $this->reglamentosRoot.'/public/reglamentos-feria/10/anexo.pdf';
        File::ensureDirectoryExists(dirname($ruta));
        File::put($ruta, '%PDF-1.4 anexo de prueba');

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->get('/reglamentos-expositor/2/descargar')
            ->assertNotFound();
    }

    public function test_company_cannot_access_regulations_for_another_companys_event(): void
    {
        $this->crearEvento(10, 'Feria de otra empresa', today()->addDays(30)->toDateString());
        $this->crearContrato(10, 2);
        DB::table('reglamentos_feria')->insert([
            'id_reglamento' => 1,
            'id_feria' => 10,
            'nombre_archivo' => 'reglamento.pdf',
            'ruta_archivo' => 'reglamentos-feria/10/reglamento.pdf',
            'nombre_reglamento' => 'Reglamento',
            'descripcion' => 'Documento.',
        ]);

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/reglamentos-expositor/eventos/10')
            ->assertNotFound();

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/reglamentos-expositor/1/descargar')
            ->assertNotFound();
    }

    private function crearEvento(int $id, string $nombre, string $fechaFin): void
    {
        DB::table('eventos')->insert([
            'id_feria' => $id,
            'nombre_feria' => $nombre,
            'inicio' => today()->addDays(10)->toDateString(),
            'fecha_inicio' => today()->addDays(10)->toDateString(),
            'fecha_fin' => $fechaFin,
        ]);
    }

    private function crearContrato(int $idFeria, int $idEmpresa, int $codigo = 1): void
    {
        DB::table('contrato')->insert([
            'id_feria' => $idFeria,
            'id_empresa' => $idEmpresa,
            'codigo_contrato' => $codigo,
            'total_stands' => 1,
        ]);
    }
}
