<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Kassa\Concerns\HasSyncUuid;
use App\Models\Kassa\Employee;
use App\Models\Kassa\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Заказ столика.
 *
 * Статусы: new — отправлен на кухню, ничего не начато; cooking — готовится; ready — всё, что не подано, готово;
 * served — всё подано, гости за столом; paid — оплачен и закрыт (попал в продажи); cancelled — отменён.
 * Пока заказ открыт, статус пересчитывается по позициям (refreshStatus).
 * Завершённый заказ (paid, cancelled) больше не меняется и уходит на онлайн-сервер (synced_at).
 */
class Order extends Model
{
    use BelongsToStore;
    use HasSyncUuid;

    public const STATUSES = ['new', 'cooking', 'ready', 'served', 'paid', 'cancelled'];

    public const OPEN = ['new', 'cooking', 'ready', 'served'];

    public const FINAL = ['paid', 'cancelled'];

    public const PAYMENTS = ['cash', 'card'];

    public const STATUS_NAMES = [
        'new' => 'Новый', 'cooking' => 'Готовится', 'ready' => 'Готов', 'served' => 'Подан',
        'paid' => 'Оплачен', 'cancelled' => 'Отменён',
    ];

    protected $table = 'restaurant_orders';

    protected $fillable = [
        'uuid', 'number', 'table_id', 'table_name', 'waiter_id', 'waiter_name', 'guests', 'comment', 'status', 'total',
        'payment', 'received', 'change_amount', 'sale_id', 'closed_by', 'bill_at', 'paid_at', 'cancelled_at',
        'cancel_reason', 'synced_at', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'number' => 'integer',
        'table_id' => 'integer',
        'waiter_id' => 'integer',
        'guests' => 'integer',
        'total' => 'integer',
        'received' => 'integer',
        'change_amount' => 'integer',
        'sale_id' => 'integer',
        'closed_by' => 'integer',
        'bill_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function tickets()
    {
        return $this->hasMany(KitchenTicket::class, 'order_id');
    }

    public function table()
    {
        return $this->belongsTo(Table::class, 'table_id')->withTrashed();
    }

    public function waiter()
    {
        return $this->belongsTo(Employee::class, 'waiter_id')->withoutGlobalScopes();
    }

    public function closer()
    {
        return $this->belongsTo(Employee::class, 'closed_by')->withoutGlobalScopes();
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    /** Позиции, которые не отменены. */
    public function activeItems(): Collection
    {
        return $this->items->where('status', '!=', 'cancelled')->values();
    }

    /**
     * Сумма и статус открытого заказа по его позициям: отменённые не считаются,
     * поданные не влияют на «готовность».
     */
    public function refreshStatus(): void
    {
        if ($this->isFinal()) {
            return;
        }
        $this->load('items');
        $active = $this->activeItems();
        $this->total = (int) $active->sum(fn (OrderItem $i) => $i->qty * $i->price);
        $this->status = OrderItem::aggregate($active);
    }
}
