<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\OpenCart;

use AneePay\Tests\Support\OperationIdContractTestCase;

final class OperationIdTest extends OperationIdContractTestCase
{
    protected function toUuid($operation_id)
    {
        $client = new \AneePay_Client([], null);

        return $client->operation_id_to_uuid($operation_id);
    }
}
