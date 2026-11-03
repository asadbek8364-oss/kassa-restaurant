<?php

namespace App\Support\Kassa;

use App\Models\Kassa\Store;
use Closure;

/**
 * Магазин, в котором идёт текущий запрос. Его выставляет вход по токену (AuthenticateKassa)
 * или экран входа; по нему StoreScope ограничивает все запросы к товарам, документам и сотрудникам.
 * Если магазин не выбран (консоль, первый запуск) — берётся первый магазин, а при пустой базе он создаётся.
 */
class StoreContext
{
    private ?Store $store = null;

    public function store(): Store
    {
        return $this->store ??= Store::query()->orderBy('id')->first()
            ?? Store::create(['name' => 'Мой магазин']);
    }

    public function id(): int
    {
        return $this->store()->id;
    }

    public function set(Store $store): void
    {
        $this->store = $store;
    }

    public function forget(): void
    {
        $this->store = null;
    }

    /**
     * Выполняет код в другом магазине и возвращает прежний: сводка по всем магазинам,
     * отправка офлайн-чека, пробитого в другом магазине.
     */
    public function run(Store $store, Closure $fn): mixed
    {
        $previous = $this->store;
        $this->store = $store;
        try {
            return $fn($store);
        } finally {
            $this->store = $previous;
        }
    }
}
