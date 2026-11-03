<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Restaurant\Concerns\SyncsFromOnline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Настройки ресторана (одна строка на магазин).
 * Норматив приготовления и тексты чека приходят с онлайн-сервера; принтер и параметры синхронизации —
 * настройки этого сервера и между серверами не передаются.
 */
class Settings extends Model
{
    use BelongsToStore;
    use SyncsFromOnline;

    public const PRINTER_MODES = ['off', 'network', 'browser'];

    /** Кодовая страница кириллицы и номер таблицы для команды ESC t n (Epson и совместимые). */
    public const CODEPAGES = ['cp866' => 17, 'cp1251' => 46];

    /** Ширина ленты в символах шрифта A: 80 мм — 48, 58 мм — 32. */
    public const WIDTHS = [48, 42, 32];

    protected $table = 'restaurant_settings';

    protected $fillable = [
        'cook_minutes', 'receipt_header', 'receipt_footer',
        'printer_mode', 'printer_host', 'printer_port', 'printer_codepage', 'printer_width',
        'sync_url', 'sync_token', 'sync_token_hash', 'sync_token_hint', 'sync_token_issued_at',
    ];

    protected $hidden = ['sync_token', 'sync_token_hash'];

    protected $attributes = [
        'cook_minutes' => 15,
        'printer_mode' => 'off',
        'printer_port' => 9100,
        'printer_codepage' => 'cp866',
        'printer_width' => 48,
    ];

    protected $casts = [
        'version' => 'integer',
        'synced_at' => 'datetime',
        'cook_minutes' => 'integer',
        'printer_port' => 'integer',
        'printer_width' => 'integer',
        'sync_token' => 'encrypted',
        'sync_token_issued_at' => 'datetime',
    ];

    public function syncFields(): array
    {
        return ['cook_minutes', 'receipt_header', 'receipt_footer'];
    }

    /** Настройки текущего ресторана; создаются при первом обращении. */
    public static function current(): self
    {
        $found = static::query()->first();
        if ($found) {
            return $found;
        }
        try {
            return static::query()->create([]);
        } catch (UniqueConstraintViolationException) {
            return static::query()->firstOrFail(); // параллельный запрос успел создать
        }
    }

    public function printingEnabled(): bool
    {
        return $this->printer_mode === 'network' || $this->printer_mode === 'browser';
    }

    /** Локальный сервер знает, куда и с каким токеном синхронизироваться. */
    public function syncConfigured(): bool
    {
        return (string) $this->sync_url !== '' && (string) $this->sync_token !== '';
    }
}
