<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\WooCommerce;

use AneePay\Tests\Support\OperationIdContractTestCase;

final class OperationIdTest extends OperationIdContractTestCase
{
    protected function toUuid($operation_id)
    {
        return aneepay_operation_id_to_uuid($operation_id);
    }
}
