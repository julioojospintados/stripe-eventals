<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Your files</x-slot>
        <x-slot name="description">Large exports are prepared in the background and kept here for {{ \App\Services\CsvExports::KEEP_DAYS }} days. Only you can download them.</x-slot>

        @forelse ($this->getFiles() as $file)
            <p>
                <x-filament::link :href="route('exports.download', $file['name'])" icon="heroicon-o-arrow-down-tray">{{ $file['name'] }}</x-filament::link>
                · {{ \Illuminate\Support\Carbon::createFromTimestamp($file['time'])->timezone('Europe/Lisbon')->format('d/m/Y H:i') }}
                · {{ number_format($file['size'] / 1024, 1) }} KB
            </p>
        @empty
            <p>No files yet. Small exports download straight away from the buttons above.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
