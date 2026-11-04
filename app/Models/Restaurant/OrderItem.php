<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Позиция заказа: блюдо × количество с комментарием («без лука»).
 * Название и цена запоминаются в момент заказа. batch — номер отправки на кухню (1 — заказ, 2+ — дозаказ).
 * Статусы: new → cooking → ready → served; cancelled — отменена.
 */
class OrderItem extends Model
{
    use BelongsToStore;

    public const STATUSES = ['new', 'cooking', 'ready', 'served', 'cancelled'];

    /** Позиции, которые видит кухня. */
    public const ON_BOARD = ['new', 'cooking', 'ready'];

    public const STATUS_NAMES = ['new' => 'Новое', 'cooking' => 'Готовится', 'ready' => 'Готово', 'served' => 'Подано', 'cancelled' => 'Отменено'];

    protected $table = 'restaurant_order_items';

    protected $fillable = [
        'order_id', 'ticket_id', 'batch', 'dish_id', 'name', 'price', 'qty', 'comment', 'status',
        'sent_at', 'cooking_at', 'ready_at', 'served_at', 'cancelled_at', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'ticket_id' => 'integer',
        'batch' => 'integer',
        'dish_id' => 'integer',
        'price' => 'integer',
        'qty' => 'integer',
        'sent_at' => 'datetime',
        'cooking_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function ticket()
    {
        return $this->belongsTo(KitchenTicket::class, 'ticket_id');
    }

    public function dish()
    {
        return $this->belongsTo(Dish::class, 'dish_id')->withTrashed();
    }

    /**
     * Общий статус набора неотменённых позиций (заказа или кухонного тикета):
     * всё подано — served; всё неподанное готово — ready; ничего не начато — new; иначе — cooking.
     */
    public static function aggregate(Collection $active): string
    {
        $pending = $active->where('status', '!=', 'served');

        return match (true) {
            $active->isEmpty() => 'new',
            $pending->isEmpty() => 'served',
            $pending->every(fn (self $i) => $i->status === 'ready') => 'ready',
            $pending->every(fn (self $i) => $i->status === 'new') => 'new',
            default => 'cooking',
        };
    }
}
