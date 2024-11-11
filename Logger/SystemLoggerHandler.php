<?php

namespace Algolia\AlgoliaSearch\Logger;

use Magento\Framework\Logger\Handler\System;
use Monolog\Logger;

/** Only log errors to system log */
class SystemLoggerHandler extends System
{
    protected $loggerType = Logger::ERROR;
}
