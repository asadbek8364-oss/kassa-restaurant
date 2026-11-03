<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Kassa\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * Отправка заказа на кухню: первая — сам заказ (batch 1), следующие — дозаказы.
 * На каждую отправку кухня получает отдельный чек и отдельную карточку на доске.
 */
class KitchenTicket extends Model
{
    use BelongsToStore;

    protected $table = 'restaurant_kitchen_tickets';

    protected $fillable = ['order_id', 'batch', 'employee_id', 'comment', 'client_uuid', 'sent_at'];

    protected $casts = [
        'order_id' => 'integer',
        'batch' => 'integer',
        'employee_id' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'ticket_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withoutGlobalScopes();
    }
}
