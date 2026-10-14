<?php

namespace Tests\Base\Estate\Controller;

use Base\Admin\Test\CrudFieldsTestCase;

/**
 * The fields marked required on this bundle's back office screens, against
 * what its records need (see CrudFieldsTestCase): only those the record
 * cannot do without, all of them, an optional one able to take an empty
 * value, one field per property, a slug following its title.
 */
final class CrudRequiredFieldsTest extends CrudFieldsTestCase
{
    protected static function sourceDirectory(): string
    {
        return __DIR__.'/../../src';
    }
}
