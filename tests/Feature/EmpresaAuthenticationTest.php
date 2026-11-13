<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmpresaAuthenticationTest extends TestCase
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
            $table->string('nit')->nullable();
            $table->string('usuario')->nullable();
            $table->string('pass');
            $table->string('nombre_empresa')->nullable();
            $table->boolean('es_activo')->nullable();
        });
        Schema::create('eventos', function (Blueprint $table) {
            $table->increments('id_feria');
            $table->string('nombre_feria')->nullable();
            $table->string('tipo_credenciales')->nullable();
            $table->date('fecha_fin')->nullable();
        });
        Schema::create('contrato', function (Blueprint $table) {
            $table->increments('id_contrato');
            $table->unsignedInteger('id_feria');
            $table->unsignedInteger('id_empresa');
            $table->integer('codigo_contrato');
            $table->integer('total_stands');
        });
        Schema::create('empresa_evento_credenciales', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_empresa');
            $table->unsignedInteger('id_feria');
            $table->boolean('estado');
        });
    }

    public function test_successful_login_keeps_the_password_hashed(): void
    {
        $this->seedEligibleCompany(Hash::make('correct-password'));

        $response = $this->postJson('/login', [
            'identificador' => '123456789',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $storedPassword = DB::table('empresas')->value('pass');
        $this->assertNotSame('correct-password', $storedPassword);
        $this->assertTrue(Hash::check('correct-password', $storedPassword));
    }

    public function test_csrf_token_endpoint_is_not_cacheable(): void
    {
        $this->getJson('/csrf-token')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['token']);
    }

    public function test_successful_login_and_logout_keep_a_legacy_plaintext_password_unchanged(): void
    {
        $this->seedEligibleCompany('legacy-password');

        $response = $this->postJson('/login', [
            'identificador' => '123456789',
            'password' => 'legacy-password',
        ]);

        $response->assertOk();
        $this->assertSame('legacy-password', DB::table('empresas')->value('pass'));

        $this->postJson('/logout')->assertOk();
        $this->assertSame('legacy-password', DB::table('empresas')->value('pass'));
    }

    public function test_company_cannot_search_exhibitors_for_an_event_it_cannot_access(): void
    {
        $this->seedEligibleCompany(Hash::make('password'));
        DB::table('empresas')->insert([
            'nit' => '987654321',
            'usuario' => 'otra-empresa',
            'pass' => Hash::make('password'),
            'nombre_empresa' => 'Otra empresa',
        ]);
        DB::table('eventos')->insert([
            'id_feria' => 20,
            'nombre_feria' => 'Evento de otra empresa',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
        ]);
        DB::table('contrato')->insert([
            'id_feria' => 20,
            'id_empresa' => 2,
            'codigo_contrato' => 1,
            'total_stands' => 1,
        ]);

        $this->actingAs(Empresa::query()->findOrFail(1), 'empresa')
            ->getJson('/acreditaciones/expositores/buscar?ci=12345&id_feria=20')
            ->assertNotFound();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $this->seedEligibleCompany(Hash::make('correct-password'));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', [
                'identificador' => '123456789',
                'password' => 'incorrect-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/login', [
            'identificador' => '123456789',
            'password' => 'incorrect-password',
        ])->assertTooManyRequests();
    }

    private function seedEligibleCompany(string $password): void
    {
        DB::table('empresas')->insert([
            'nit' => '123456789',
            'usuario' => 'empresa-prueba',
            'pass' => $password,
            'nombre_empresa' => 'Empresa de prueba',
            'es_activo' => true,
        ]);
        DB::table('eventos')->insert([
            'id_feria' => 10,
            'nombre_feria' => 'Feria de prueba',
            'tipo_credenciales' => 'Expositor',
            'fecha_fin' => today()->addDay()->toDateString(),
        ]);
        DB::table('contrato')->insert([
            'id_feria' => 10,
            'id_empresa' => 1,
            'codigo_contrato' => 1,
            'total_stands' => 1,
        ]);
    }
}
