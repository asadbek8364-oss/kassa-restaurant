<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Kassa\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * Оплата картой через банковский терминал кассы: сумма заказа ушла на терминал, гость прикладывает карту.
 * pending — ждём терминал; approved — оплачено (заказ оплачен этой операцией); declined — отказ банка;
 * cancelled — отменили на кассе или на терминале; error — терминал недоступен или не ответил.
 * Заказ закрывается только при approved. Пока операция pending, заказ нельзя менять и оплатить иначе.
 */
class TerminalPayment extends Model
{
    use BelongsToStore;

    public const STATUSES = ['pending', 'approved', 'declined', 'cancelled', 'error'];

    public const STATUS_NAMES = [
        'pending' => 'Ожидает терминал', 'approved' => 'Оплачено', 'declined' => 'Отклонено',
        'cancelled' => 'Отменено', 'error' => 'Ошибка терминала',
    ];

    protected $table = 'restaurant_terminal_payments';

    protected $fillable = [
        'order_id', 'employee_id', 'amount', 'driver', 'status', 'message', 'reference', 'auth_code', 'card',
        'meta', 'errors', 'finished_at',
    ];

    protected $attributes = [
        'status' => 'pending',
        'errors' => 0,
    ];

    protected $casts = [
        'order_id' => 'integer',
        'employee_id' => 'integer',
        'amount' => 'integer',
        'meta' => 'array',
        'errors' => 'integer',
        'finished_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withoutGlobalScopes();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
