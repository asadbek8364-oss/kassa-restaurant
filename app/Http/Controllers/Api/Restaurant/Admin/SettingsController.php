<?php

namespace App\Http\Controllers\Api\Restaurant\Admin;

use App\Http\Controllers\Api\Restaurant\RestaurantController;
use App\Models\Kassa\Store;
use App\Models\Restaurant\PrintJob;
use App\Models\Restaurant\Settings;
use App\Services\Restaurant\Printing\PrinterException;
use App\Services\Restaurant\Printing\PrintQueue;
use App\Services\Restaurant\Printing\ReceiptTemplates;
use App\Support\Kassa\Presenter as KassaPresenter;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Настройки: параметры ресторана, принтер кухни (сетевой ESC/POS или печать из браузера), очередь печати. */
class SettingsController extends RestaurantController
{
    public function show(): JsonResponse
    {
        return response()->json([
            'store' => KassaPresenter::store(Store::current()),
            'settings' => Presenter::settings(Settings::current()),
            'codepages' => array_keys(Settings::CODEPAGES),
            'widths' => Settings::WIDTHS,
        ]);
    }

    /** Название, адрес, норматив приготовления, тексты гостевого чека. */
    public function updateRestaurant(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'name' => 'required|string|max:60',
            'address' => 'nullable|string|max:120',
            'cook_minutes' => 'required|integer|min:1|max:240',
            'receipt_header' => 'nullable|string|max:120',
            'receipt_footer' => 'nullable|string|max:120',
        ]);
        $store = Store::current();
        $settings = Settings::current();
        $store->fill(['name' => trim($data['name']), 'address' => trim((string) ($data['address'] ?? '')) ?: null]);
        $settings->fill([
            'cook_minutes' => (int) $data['cook_minutes'],
            'receipt_header' => trim((string) ($data['receipt_header'] ?? '')) ?: null,
            'receipt_footer' => trim((string) ($data['receipt_footer'] ?? '')) ?: null,
        ]);
        // название и адрес — часть настроек ресторана, которые синхронизируются
        if ($store->isDirty(['name', 'address']) && ! $settings->isDirty($settings->syncFields())) {
            $settings->markChangedForSync();
        }
        $store->save();
        $settings->save();

        return $this->show();
    }

    public function updatePrinter(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'printer_mode' => ['required', Rule::in(Settings::PRINTER_MODES)],
            'printer_host' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9.\-:]+$/'],
            'printer_port' => 'nullable|integer|min:1|max:65535',
            'printer_codepage' => ['required', Rule::in(array_keys(Settings::CODEPAGES))],
            'printer_width' => ['required', 'integer', Rule::in(Settings::WIDTHS)],
        ]);
        if ($data['printer_mode'] === 'network' && empty($data['printer_host'])) {
            throw ValidationException::withMessages(['printer_host' => 'Укажите IP-адрес принтера, например 192.168.1.50']);
        }
        Settings::current()->update([
            'printer_mode' => $data['printer_mode'],
            'printer_host' => $data['printer_host'] ?? null,
            'printer_port' => (int) ($data['printer_port'] ?? 9100),
            'printer_codepage' => $data['printer_codepage'],
            'printer_width' => (int) $data['printer_width'],
        ]);

        return $this->show();
    }

    /**
     * Тестовая печать. Сетевой принтер — печатает сразу и сообщает результат;
     * печать из браузера — задание забирает экран кухни.
     */
    public function testPrint(PrintQueue $queue, ReceiptTemplates $templates): JsonResponse
    {
        $settings = Settings::current();
        if (! $settings->printingEnabled()) {
            throw ValidationException::withMessages(['printer_mode' => 'Печать выключена — выберите сетевой принтер или печать из браузера']);
        }
        $document = $templates->test(Store::current(), $settings);
        $job = PrintJob::create(['kind' => 'test', 'title' => 'Тестовая печать', 'document' => $document]);

        if ($settings->printer_mode === 'network') {
            try {
                $queue->printNow($job);
            } catch (PrinterException $e) {
                return response()->json(['ok' => false, 'message' => $e->getMessage(), 'job' => Presenter::printJob($job->fresh())], 422);
            }

            return response()->json(['ok' => true, 'message' => 'Отправлено на принтер '.$settings->printer_host.':'.$settings->printer_port, 'job' => Presenter::printJob($job->fresh())]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Задание отправлено на экран кухни — он напечатает его через браузер',
            'job' => Presenter::printJob($job->fresh()),
            'document' => $document,
        ]);
    }

    public function printJobs(): JsonResponse
    {
        return response()->json([
            'jobs' => PrintJob::orderByDesc('id')->limit(50)->get()->map(fn ($j) => Presenter::printJob($j))->all(),
        ]);
    }

    public function retryJob(PrintJob $job, PrintQueue $queue): JsonResponse
    {
        if ($job->status === 'done') {
            throw ValidationException::withMessages(['job' => 'Этот чек уже напечатан — для копии используйте повторную печать на экране кухни']);
        }
        $queue->retry($job);

        return response()->json(['job' => Presenter::printJob($job->fresh())]);
    }
}
