<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): \Illuminate\Foundation\Application
    {
        $app=parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'aroma_med_test' || config('database.connections.mysql.username') !== 'aroma_test') {
            throw new \RuntimeException('Tests must use the isolated aroma_med_test MySQL database and aroma_test account.');
        }
        return $app;
    }
}
