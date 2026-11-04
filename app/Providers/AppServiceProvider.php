<?php

namespace App\Providers;

use App\Services\Restaurant\Printing\PrinterTransport;
use App\Services\Restaurant\Printing\PrintQueue;
use App\Services\Restaurant\Printing\TcpPrinterTransport;
use App\Services\Restaurant\Terminal\TcpTerminalTransport;
use App\Services\Restaurant\Terminal\TerminalTransport;
use App\Support\Kassa\StoreContext;
use App\Support\ProductionSecurityBaseline;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // «Касса и склад»: магазин текущего запроса (свой экземпляр на каждый запрос).
        $this->app->scoped(StoreContext::class);

        // Ресторан: сетевой принтер ESC/POS (в тестах подменяется FakePrinterTransport) и очередь печати.
        $this->app->singleton(PrinterTransport::class, TcpPrinterTransport::class);
        $this->app->scoped(PrintQueue::class);
        // Платёжный терминал кассы: связь с терминалом в локальной сети (в тестах — FakeTerminalTransport).
        $this->app->singleton(TerminalTransport::class, TcpTerminalTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(env('API_RATE_LIMIT_PER_MINUTE', 120))
                ->by($request->user()?->id ?: $request->ip());
        });

        // «Касса и склад»: у каждого маршрута свой счётчик. Числовой throttle:N,1 делит один счётчик
        // на IP между всеми маршрутами, а телефоны магазина обычно выходят в сеть с одного IP.
        RateLimiter::for('kassa-public', fn (Request $request) => Limit::perMinute(60)->by('kassa-public|'.$request->ip()));
        RateLimiter::for('kassa-setup', fn (Request $request) => Limit::perMinute(5)->by('kassa-setup|'.$request->ip()));
        RateLimiter::for('kassa-login', fn (Request $request) => Limit::perMinute(30)->by('kassa-login|'.$request->ip()));
        RateLimiter::for('kassa-api', fn (Request $request) => Limit::perMinute(300)->by('kassa-api|'.sha1((string) ($request->bearerToken() ?: $request->ip()))));
        // Ресторан: запросы локального сервера к онлайн-серверу (пачки синхронизации)
        RateLimiter::for('restaurant-sync', fn (Request $request) => Limit::perMinute(120)->by('restaurant-sync|'.sha1((string) ($request->bearerToken() ?: $request->ip()))));

        // API documentation (Scramble) is public — open source project, docs should be viewable
        // by anyone. Protect via SCRAMBLE_DOCS_TOKEN env if desired (RestrictedDocsAccess).
        Gate::define('viewApiDocs', fn () => true);

        $issues = ProductionSecurityBaseline::issues();

        if ($issues !== []) {
            Log::warning('Production security baseline check failed.', [
                'issues' => $issues,
            ]);
        }
    }
}
