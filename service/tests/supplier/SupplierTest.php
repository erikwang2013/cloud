<?php

namespace Tests\supplier;

use App\supplier\model\Supplier;
use PHPUnit\Framework\TestCase;

final class SupplierTest extends TestCase
{
    public function testContactPiiNeverSerialized(): void
    {
        $hidden = (new Supplier())->getHidden();
        $this->assertContains('contact_name', $hidden);
        $this->assertContains('contact_phone', $hidden);
        $this->assertContains('contact_email', $hidden);
    }

    public function testExportCanRestoreContactColumns(): void
    {
        // 导出是受控 admin 功能，需 makeVisible 恢复三字段（见 SupplierController::export）
        $model = new Supplier();
        $model->makeVisible(['contact_name', 'contact_email', 'contact_phone']);
        $this->assertNotContains('contact_name', $model->getHidden());
        $this->assertNotContains('contact_email', $model->getHidden());
        $this->assertNotContains('contact_phone', $model->getHidden());
    }
}
