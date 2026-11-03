<?php

namespace App\Services\Restaurant\Sync;

use RuntimeException;

/** Понятная администратору причина, по которой синхронизация не прошла. */
class SyncException extends RuntimeException {}
