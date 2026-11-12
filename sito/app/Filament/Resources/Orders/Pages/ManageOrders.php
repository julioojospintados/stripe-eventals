<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\CompReason;
use App\Enums\TicketKind;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\ComplimentaryService;
use App\Services\ShopException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class ManageOrders extends ManageRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Riconciliazione con Stripe fuori dalla richiesta: il comando va in coda, il rapporto nel registro audit.
            Action::make('reconcile')
                ->label('Reconcile with Stripe')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->authorize(fn () => auth()->user()->can('reconcile', Order::class))
                ->requiresConfirmation()
                ->modalDescription('Compares the last 48 hours with Stripe in the background. The report goes to the audit log; admins get an email if something differs.')
                ->action(function () {
                    Artisan::queue('eventals:reconcile');
                    AuditLog::record('stripe.reconcile_requested', null, auth()->user());
                    Notification::make()->success()->title('Reconciliation queued')->send();
                }),
            // Omaggio per speaker, staff o sponsor: ordine a 0 €, conta nella capienza, biglietto per email, audit.
            Action::make('issueComplimentary')
                ->label('Issue complimentary ticket')
                ->icon(Heroicon::OutlinedGift)
                ->authorize(fn () => auth()->user()->can('issueComplimentary', Order::class))
                ->schema([
                    Select::make('ticket_type_id')->label('Ticket type')->required()
                        ->options(fn () => TicketType::orderBy('position')->get()->mapWithKeys(fn ($t) => [$t->id => $t->name['en']]))
                        ->default(fn () => TicketType::where('kind', TicketKind::StaffPass)->value('id')),
                    TextInput::make('name')->label('Attendee name')->required()->maxLength(200),
                    TextInput::make('email')->label('Attendee email')->email()->required()->maxLength(254),
                    Select::make('reason')->options(CompReason::class)->required(),
                    Textarea::make('note')->maxLength(500),
                ])
                ->action(function (array $data, Action $action) {
                    try {
                        $order = app(ComplimentaryService::class)->issue(
                            auth()->user(), TicketType::findOrFail($data['ticket_type_id']), $data['name'], $data['email'],
                            CompReason::from($data['reason'] instanceof CompReason ? $data['reason']->value : $data['reason']), $data['note'] ?? null,
                        );
                        Notification::make()->success()->title('Complimentary ticket issued')
                            ->body('Sent by email to '.$order->buyer_email.'.')->send();
                    } catch (ShopException $e) {
                        Notification::make()->danger()->title(__('shop.'.$e->getMessage()))->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
