<?php

namespace App\Services\Restaurant\Terminal;

use RuntimeException;

/** Нет связи с терминалом, он не ответил или ответил непонятно. */
class TerminalException extends RuntimeException {}
