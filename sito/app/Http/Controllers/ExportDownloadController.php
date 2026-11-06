<?php

namespace App\Http\Controllers;

use App\Services\CsvExports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Scarica un export generato in coda: solo dallo staff che l'ha chiesto (il file sta nella sua cartella privata). */
class ExportDownloadController
{
    public function __invoke(Request $request, string $file)
    {
        $user = $request->user();
        $type = explode('-', $file)[0];
        abort_unless($user->isStaff() && $user->can(CsvExports::PERMISSIONS[$type] ?? 'none'), 403);

        $path = CsvExports::path($user, $file);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
