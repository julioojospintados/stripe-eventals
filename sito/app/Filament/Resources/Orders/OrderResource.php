<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Jobs\RefundOrderFully;
use App\Models\AuditLog;
use App\Models\Order;
use App\Services\ComplimentaryService;
use App\Services\PaymentService;
use App\Services\ShopException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Ordini in sola lettura, con righe e biglietti. Azioni: rimborso completo (commerciale e admin, con il job
 * RefundOrderFully del passo 2), revoca di un omaggio, decisione sugli ordini needs_review (emetti o rimborsa). Ogni
 * azione ha il suo permesso; senza orders.view_amounts non si vedono gli importi.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'buyer_email';

    public static function canSeeAmounts(): bool
    {
        return (bool) auth()->user()?->can('orders.view_amounts');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('id')->label('Order')->copyable(),
            TextEntry::make('status')->badge(),
            TextEntry::make('review_reason')->label('To review')->placeholder('—'),
            TextEntry::make('origin'),
            TextEntry::make('buyer_email'),
            TextEntry::make('price_role'),
            TextEntry::make('priceTier.code')->label('Tier')->placeholder('—'),
            TextEntry::make('created_at')->dateTime('d/m/Y H:i', 'Europe/Lisbon'),
            TextEntry::make('paid_at')->dateTime('d/m/Y H:i', 'Europe/Lisbon')->placeholder('—'),
            TextEntry::make('comp_reason')->placeholder('—'),
            TextEntry::make('subtotal_cents')->label('Subtotal')->money('EUR', divideBy: 100)->visible(self::canSeeAmounts(...)),
            TextEntry::make('discount_cents')->label('Discount')->money('EUR', divideBy: 100)->visible(self::canSeeAmounts(...)),
            TextEntry::make('total_cents')->label('Total')->money('EUR', divideBy: 100)->visible(self::canSeeAmounts(...)),
            TextEntry::make('discountCode.code')->label('Discount code')->placeholder('—'),
            TextEntry::make('stripe_amount_total_cents')->label('Paid on Stripe')->money('EUR', divideBy: 100)->placeholder('—')->visible(self::canSeeAmounts(...)),
            TextEntry::make('stripe_payment_intent_id')->label('Stripe payment')->placeholder('—')->visible(self::canSeeAmounts(...)),
            TextEntry::make('stripe_invoice_url')->label('Invoice')->formatStateUsing(fn () => 'Invoice')->placeholder('—')
                ->url(fn (Order $record) => $record->stripe_invoice_url, shouldOpenInNewTab: true)->visible(self::canSeeAmounts(...)),
            Section::make('Items')->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('stripe_line_name')->label('Ticket'),
                    TextEntry::make('quantity'),
                    TextEntry::make('total_cents')->label('Total')->money('EUR', divideBy: 100)->visible(self::canSeeAmounts(...)),
                ]),
            ]),
            Section::make('Tickets')->columnSpanFull()->schema([
                RepeatableEntry::make('tickets')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('short_code')->label('Code'),
                    TextEntry::make('attendee_name')->label('Name'),
                    TextEntry::make('attendee_email')->label('Email'),
                    TextEntry::make('status')->badge(),
                ]),
            ]),
            Section::make('Refunds')->columnSpanFull()->visible(self::canSeeAmounts(...))->schema([
                RepeatableEntry::make('refunds')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('amount_cents')->label('Amount')->money('EUR', divideBy: 100),
                    TextEntry::make('cause')->placeholder('—'),
                    TextEntry::make('source'),
                    TextEntry::make('status')->badge(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime('d/m/Y H:i', 'Europe/Lisbon')->sortable(),
                TextColumn::make('buyer_email')->searchable(),
                TextColumn::make('status')->badge()->color(fn (OrderStatus $state) => match ($state) {
                    OrderStatus::Paid => 'success',
                    OrderStatus::NeedsRefund, OrderStatus::NeedsReview => 'danger',
                    OrderStatus::Pending, OrderStatus::Processing => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('review_reason')->label('To review')->badge()->color('danger')->placeholder('—'),
                TextColumn::make('origin'),
                TextColumn::make('items_count')->counts('items')->label('Lines'),
                TextColumn::make('total_cents')->label('Total')->money('EUR', divideBy: 100)->visible(self::canSeeAmounts(...)),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::class),
                SelectFilter::make('origin')->options(OrderOrigin::class),
            ])
            ->recordActions([
                ViewAction::make(),
                self::refundAction(),
                self::revokeAction(),
                self::approveReviewAction(),
                self::refundReviewAction(),
            ]);
    }

    /** Rimborso completo con il job del passo 2; lo stato refunded arriva dal webhook charge.refunded. */
    public static function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Refund in full')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('refund')
            ->visible(fn (Order $record) => $record->origin === OrderOrigin::Checkout && $record->stripe_payment_intent_id
                && in_array($record->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::NeedsRefund], true))
            ->requiresConfirmation()
            ->modalDescription('The full amount goes back to the customer\'s card through Stripe. Tickets are cancelled when Stripe confirms the refund.')
            ->schema([
                Select::make('cause')->required()->options([
                    'event_cancelled' => 'Event cancelled',
                    'no_capacity' => 'Paid without seats',
                    'other' => 'Other',
                ]),
                Textarea::make('reason')->required()->maxLength(500),
            ])
            ->action(function (Order $record, array $data) {
                AuditLog::record('order.refund_requested', $record, auth()->user(), $data);
                RefundOrderFully::dispatch($record->id, $data['cause'], auth()->id(), $data['reason']);
                Notification::make()->success()->title('Refund requested')
                    ->body('Stripe confirms it in a few minutes; the order then becomes refunded.')->send();
            });
    }

    /** needs_review (importo diverso dal totale): i posti sono presi, lo staff decide di emettere i biglietti. */
    public static function approveReviewAction(): Action
    {
        return self::reviewAction('approveReview', 'Issue tickets', 'The amount paid on Stripe differs from the order total. The tickets are issued and sent by email.')
            ->icon(Heroicon::OutlinedCheckCircle)->color('success');
    }

    /** needs_review: lo staff decide di rimborsare; i posti tornano liberi, il rimborso va in coda. */
    public static function refundReviewAction(): Action
    {
        return self::reviewAction('refundReview', 'Refund instead', 'No tickets are issued, the seats are released and the full amount paid goes back through Stripe.')
            ->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger');
    }

    private static function reviewAction(string $method, string $label, string $description): Action
    {
        return Action::make($method)
            ->label($label)
            ->authorize('review')
            ->visible(fn (Order $record) => $record->status === OrderStatus::NeedsReview)
            ->requiresConfirmation()
            ->modalDescription($description)
            ->schema([Textarea::make('note')->label('Why')->required()->maxLength(500)])
            ->action(function (Order $record, array $data) use ($method) {
                try {
                    app(PaymentService::class)->{$method}($record, auth()->user(), $data['note']);
                    Notification::make()->success()->title('Decision saved')->send();
                } catch (ShopException $e) {
                    Notification::make()->danger()->title(__('shop.'.$e->getMessage()))->send();
                }
            });
    }

    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke complimentary')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('issueComplimentary')
            ->visible(fn (Order $record) => $record->origin === OrderOrigin::Complimentary && $record->status === OrderStatus::Paid)
            ->requiresConfirmation()
            ->schema([Textarea::make('note')->label('Why')->required()->maxLength(500)])
            ->action(function (Order $record, array $data) {
                try {
                    app(ComplimentaryService::class)->revoke($record, auth()->user(), $data['note']);
                    Notification::make()->success()->title('Complimentary ticket revoked: the seat is free again')->send();
                } catch (ShopException $e) {
                    Notification::make()->danger()->title(__('shop.'.$e->getMessage()))->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageOrders::route('/')];
    }
}
