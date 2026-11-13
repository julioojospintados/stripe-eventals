<?php

namespace App\Jobs;

use App\Mail\StaffAlert;
use App\Models\User;
use App\Services\CsvExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Export grande: il file si scrive in coda e compare nella pagina Export del pannello di chi l'ha chiesto. */
class GenerateCsvExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public string $type, public int $userId, public string $filename, public ?string $eventId = null) {}

    public function handle(CsvExports $exports): void
    {
        $exports->store($this->type, User::findOrFail($this->userId), $this->filename, $this->eventId);
    }

    /** Senza avviso chi ha chiesto l'export aspetterebbe un file che non arriva. */
    public function failed(Throwable $e): void
    {
        StaffAlert::raise('Export CSV non generato', ["{$this->type} per l'utente {$this->userId} ({$this->filename}): {$e->getMessage()}"]);
    }
}
