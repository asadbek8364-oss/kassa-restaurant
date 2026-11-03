<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Журнал синхронизаций. На локальном сервере — каждый запуск (direction = sync): сколько отправлено и получено,
 * ошибка. На онлайн-сервере — каждый принятый запрос локального сервера (push, pull).
 */
class SyncLog extends Model
{
    use BelongsToStore;

    protected $table = 'restaurant_sync_logs';

    protected $fillable = ['direction', 'trigger', 'status', 'sent', 'received', 'error', 'started_at', 'finished_at'];

    protected $casts = [
        'sent' => 'array',
        'received' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
