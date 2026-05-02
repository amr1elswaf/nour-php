<?php

namespace  Nour\core\http;

use Swoole\Coroutine;

class AppContext
{
    public static function get_contex():array{
        return  Coroutine::getContext()->context;
    }
}