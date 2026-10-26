<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Controllers\Record;
use Espo\Core\Api\Request;
use Espo\Custom\Tools\ActivitySummary\Report;
use stdClass;

class ActivitySummary extends Record
{
    public function getActionReport(Request $request): stdClass
    {
        $limitParam = $request->getQueryParam('limit');
        $limit = $limitParam !== null ? (int) $limitParam : 10;

        return $this->injectableFactory
            ->create(Report::class)
            ->run($limit);
    }
}
