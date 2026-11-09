<?php

namespace Tests\Base\Estate\Controller\Admin;

use Base\Admin\Controller\AbstractCrudController;
use Omnibridge\Persistence\UnitOfWork;
use PHPUnit\Framework\TestCase;

/**
 * The back office's writes go through omnibase/admin's hooks on the persistence the site chose (persistRecord(),
 * updateRecord(), removeRecord(), since the admin's step B1a), which this bundle's screens override in place of the
 * former persistEntity()/updateEntity()/removeEntity() on Doctrine's entity manager: none of them is left, so the
 * admin calls this bundle's hooks - a mandate numbered, a lease's folders, a flatmate out of the group, an expense
 * posted - on Doctrine and on omnibridge/eloquent alike.
 */
final class RecordHooksTest extends TestCase
{
    public function testTheScreensOverrideTheNewHooksOnly(): void
    {
        $overriding = [];
        foreach (glob(\dirname(__DIR__, 3).'/src/Controller/Admin/Crud/*CrudController.php') ?: [] as $file) {
            $class = 'Base\\Estate\\Controller\\Admin\\Crud\\'.basename($file, '.php');
            if (!is_subclass_of($class, AbstractCrudController::class)) {
                continue;
            }
            foreach (['persist', 'update', 'delete', 'remove'] as $hook) {
                self::assertSame(AbstractCrudController::class, (new \ReflectionMethod($class, $hook.'Entity'))->getDeclaringClass()->getName(), "$class::{$hook}Entity()");
                $record = new \ReflectionMethod($class, $hook.'Record');
                if (AbstractCrudController::class !== $record->getDeclaringClass()->getName()) {
                    self::assertSame(UnitOfWork::class, (string) $record->getParameters()[0]->getType(), "$class::{$hook}Record()");
                    $overriding[] = basename($file, 'CrudController.php').'::'.$hook;
                }
            }
        }
        sort($overriding);
        self::assertSame([
            'Lease::persist', 'Lease::update', 'LeaseParty::persist', 'LeaseParty::remove', 'LeaseParty::update',
            'Mandate::persist', 'MandateExpense::persist', 'MandateExpense::update', 'Mandator::persist', 'Mandator::update',
            'Ownership::persist', 'Ownership::update', 'OwnershipCall::persist', 'OwnershipShare::persist', 'OwnershipShare::update',
            'SaleMandate::persist', 'SaleMandate::update',
        ], $overriding);
    }
}
