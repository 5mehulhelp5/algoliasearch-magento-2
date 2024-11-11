<?php

namespace Algolia\AlgoliaSearch\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class LoggerHandler extends Base
{
    protected $fileName = '/var/log/algolia.log';
    protected $loggerType = Logger::DEBUG; // Default
}
