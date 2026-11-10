<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EnviarCredencialDigitalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $idCredencial,
        public string $correo,
        public string $nombre,
        public string $nombreFeria,
        public string $imagenBase64,
        public ?int $idUsuario = null,
    ) {
    }

    public function handle(): void
    {
        Mail::raw(
            "Estimada/o {$this->nombre}:\n\nAdjuntamos su credencial digital para nuestro evento/feria {$this->nombreFeria}.\n\nRecordamos que esta credencial es personal e intransferible.\n\nSaludos cordiales,\nEquipo Acreditación FEXCO.",
            function ($message) {
                $message->to($this->correo)
                    ->subject('Tu credencial digital - ' . $this->nombreFeria)
                    ->attachData(base64_decode($this->imagenBase64, true), 'credencial-' . Str::slug($this->nombre ?: (string) $this->idCredencial) . '.png', [
                        'mime' => 'image/png',
                    ]);
            }
        );

        if (DB::getSchemaBuilder()->hasTable('credencial_digital_descargas')) {
            DB::table('credencial_digital_descargas')->insert([
                'id_credencial' => $this->idCredencial,
                'id_usuario' => $this->idUsuario,
                'tipo_descarga' => 'envio_email',
                'created_at' => now(),
            ]);
        }
    }
}
