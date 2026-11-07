<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Foto degli speaker: ritaglio quadrato al centro, 800 × 800 al massimo, ricodificata in JPEG con GD. La ricodifica
 * toglie i dati nascosti (EXIF: fotocamera, posizione GPS). Il risultato va sul disco public (speakers/), gli
 * originali dell'import restano sul disco privato (speaker-photos/).
 */
class SpeakerPhoto
{
    public const SIZE = 800;

    /** Cartella del disco public dove il form di Filament carica l'originale, ricodificato al salvataggio. */
    public const UPLOADS = 'speakers/uploads';

    /** Cartella del disco privato dove si copiano le foto prima dell'import di speakers.csv. */
    public const IMPORT_DIR = 'speaker-photos';

    /** @return string percorso sul disco public */
    public function store(string $contents): string
    {
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw new RuntimeException('The photo is not a valid JPG or PNG image.');
        }

        $side = min(imagesx($source), imagesy($source));
        $size = min($side, self::SIZE);
        $photo = imagecreatetruecolor($size, $size);
        imagefill($photo, 0, 0, (int) imagecolorallocate($photo, 255, 255, 255));   // PNG trasparenti su bianco
        imagecopyresampled($photo, $source, 0, 0, intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2),
            $size, $size, $side, $side);

        ob_start();
        imagejpeg($photo, null, 85);
        $path = 'speakers/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }
}
