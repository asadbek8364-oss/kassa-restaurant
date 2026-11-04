<?php

namespace App\Services\Restaurant\Printing;

use App\Models\Kassa\Store;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Settings;
use App\Support\Kassa\Clock;
use Illuminate\Support\Collection;

/**
 * Шаблоны чеков для ленты 80 мм.
 *
 * - Кухонный чек: номер заказа, столик или «НАВЫНОС», официант (кассир), время, позиции крупно
 *   (количество × название), комментарии выделены инверсией. Цен и итога нет — это чек для повара.
 * - Отмена: какие позиции больше не готовить.
 * - Гостевой счёт: позиции с ценами, итог, оплата.
 */
class ReceiptTemplates
{
    /** @param Collection<int, OrderItem> $items позиции этой отправки на кухню */
    public function kitchen(Order $order, KitchenTicket $ticket, Collection $items, Settings $settings, bool $reprint = false): array
    {
        $r = new Receipt($settings->printer_width);
        $r->text('КУХНЯ', 'center', 'l', true)
            ->text($ticket->batch > 1 ? "ДОЗАКАЗ К №{$order->number}" : "ЗАКАЗ №{$order->number}", 'center', 'l', true)
            ->text(mb_strtoupper($order->place()), 'center', 'l', true, $order->isTakeaway());
        if ($order->guests) {
            $r->text('Гостей: '.$order->guests, 'center');
        }
        $r->rule()
            ->row($order->isTakeaway() ? 'Кассир' : 'Официант', $ticket->employee?->name ?? (string) $order->waiter_name)
            ->row('Отправлен', Clock::local($ticket->sent_at)->format('d.m.Y H:i'));
        if ($reprint) {
            $r->text('ПОВТОРНАЯ ПЕЧАТЬ · '.Clock::now()->format('H:i'), 'center', 'n', true, true);
        }
        $r->rule('=');
        foreach ($items as $i) {
            $r->text("{$i->qty} × {$i->name}", 'left', 't', true);
            if ($i->comment) {
                $r->text('>> '.$i->comment, 'left', 't', true, true);
            }
        }
        $r->rule('=');
        if ($ticket->comment) {
            $r->text('К ЗАКАЗУ:', 'left', 'n', true)
                ->text($ticket->comment, 'left', 't', true, true);
        }

        return $r->toArray();
    }

    /** @param Collection<int, OrderItem> $items позиции, которые больше не нужно готовить */
    public function cancel(Order $order, Collection $items, Settings $settings, ?string $by, ?string $reason): array
    {
        $r = new Receipt($settings->printer_width);
        $r->text('ОТМЕНА', 'center', 'l', true, true)
            ->text("ЗАКАЗ №{$order->number}", 'center', 'l', true)
            ->text(mb_strtoupper($order->place()), 'center', 'l', true)
            ->rule()
            ->row('Отменил', (string) $by)
            ->row('Время', Clock::now()->format('d.m.Y H:i'))
            ->rule('=')
            ->text('НЕ ГОТОВИТЬ:', 'left', 'n', true);
        foreach ($items as $i) {
            $r->text("{$i->qty} × {$i->name}", 'left', 't', true);
        }
        $r->rule('=');
        if ($reason) {
            $r->text('Причина: '.$reason);
        }

        return $r->toArray();
    }

    public function guest(Order $order, Store $store, Settings $settings): array
    {
        $r = new Receipt($settings->printer_width);
        $r->text($store->name, 'center', 'l', true);
        if ($store->address) {
            $r->text($store->address, 'center');
        }
        if ($settings->receipt_header) {
            $r->text($settings->receipt_header, 'center');
        }
        $at = $order->paid_at ?? $order->bill_at ?? now();
        $r->rule()
            ->row('Счёт № '.$order->number, Clock::local($at)->format('d.m.Y H:i'))
            ->row($order->place().($order->guests ? ' · гостей '.$order->guests : ''), ($order->isTakeaway() ? 'Кассир: ' : 'Официант: ').$this->firstName((string) $order->waiter_name))
            ->rule();

        // Одинаковые блюда по одной цене — одной строкой
        $lines = [];
        foreach ($order->items->where('status', '!=', 'cancelled') as $i) {
            $key = $i->name.'|'.$i->price;
            $lines[$key] ??= ['name' => $i->name, 'price' => $i->price, 'qty' => 0];
            $lines[$key]['qty'] += $i->qty;
        }
        $total = 0;
        foreach ($lines as $l) {
            $sum = $l['qty'] * $l['price'];
            $total += $sum;
            $r->text($l['name'])
                ->row('   '.$l['qty'].' × '.self::money($l['price']), self::money($sum));
        }
        $r->rule('=')
            ->row('ИТОГО', self::money($total), 't', true);
        if ($order->isPaid()) {
            $r->row('Оплата', $order->payment === 'cash' ? 'наличные' : 'карта');
            if ($order->payment === 'cash' && $order->received) {
                $r->row('Получено', self::money($order->received))
                    ->row('Сдача', self::money($order->change_amount));
            }
        }
        $r->rule()
            ->text($settings->receipt_footer ?: 'Спасибо, что были у нас!', 'center');

        return $r->toArray();
    }

    public function test(Store $store, Settings $settings): array
    {
        return (new Receipt($settings->printer_width))
            ->text('ТЕСТОВАЯ ПЕЧАТЬ', 'center', 'l', true)
            ->text($store->name, 'center')
            ->rule()
            ->row('Кодовая страница', $settings->printer_codepage)
            ->row('Ширина строки', $settings->printer_width.' симв.')
            ->row('Время', Clock::now()->format('d.m.Y H:i:s'))
            ->rule()
            ->text('АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ')
            ->text('абвгдеёжзийклмнопрстуфхцчшщъыьэюя № 0123456789')
            ->text('2 × Плов чайханский', 'left', 't', true)
            ->text('Крупно: СТОЛ 5', 'left', 'l', true)
            ->text('>> без лука, острое', 'left', 'n', true, true)
            ->rule('=')
            ->text('Видны русские буквы и линия во всю ширину — принтер настроен. Если вместо букв непонятные символы, смените кодовую страницу.', 'center')
            ->toArray();
    }

    /** 128000 → «128 000». */
    public static function money(int $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    private function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name));

        return $parts[0] !== '' ? $parts[0] : '—';
    }
}
