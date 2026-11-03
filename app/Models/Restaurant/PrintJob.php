<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Задание печати. document — чек в виде строк со стилями (ReceiptTemplates): из него строятся и байты ESC/POS
 * для сетевого принтера, и страница для печати из браузера кухни.
 * Статусы: pending → printing → done; при недоступности принтера — снова pending с отложенной попыткой,
 * после max_attempts — failed (повторить можно вручную).
 */
class PrintJob extends Model
{
    use BelongsToStore;

    public const KINDS = ['kitchen', 'cancel', 'guest', 'test'];

    public const KIND_NAMES = ['kitchen' => 'Кухня', 'cancel' => 'Отмена', 'guest' => 'Счёт гостю', 'test' => 'Тест'];

    protected $table = 'restaurant_print_jobs';

    protected $fillable = [
        'order_id', 'ticket_id', 'kind', 'title', 'document', 'status', 'attempts', 'last_error',
        'next_attempt_at', 'locked_until', 'printed_at', 'printed_via',
    ];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected $casts = [
        'order_id' => 'integer',
        'ticket_id' => 'integer',
        'document' => 'array',
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'locked_until' => 'datetime',
        'printed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
