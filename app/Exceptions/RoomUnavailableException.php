<?php

namespace App\Exceptions;

use Exception;

class RoomUnavailableException extends Exception
{
    protected $message = 'Комната занята на выбранное время';
    protected $code = 409;
}
