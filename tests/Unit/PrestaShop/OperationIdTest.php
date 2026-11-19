<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\PrestaShop;

use AneePay\Tests\Support\OperationIdContractTestCase;

final class OperationIdTest extends OperationIdContractTestCase
{
    protected function toUuid($operation_id)
    {
        $api = new \AneePayApi([]);

        return $api->operation_id_to_uuid($operation_id);
    }
}
