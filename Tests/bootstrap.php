<?php

use App\Security\RegistrationConfiguration;
use Cube\Core\Autoloader;
use Cube\Data\Database\Database;
use Cube\Env\Configuration;
use Cube\Env\Session\Drivers\PHPGlobalSession;
use Cube\Env\Session\SessionConfiguration;
use Cube\Test\TestContext;

require_once __DIR__. "/../vendor/autoload.php";

Autoloader::initialize(realpath(__DIR__ . '/..'));

Cube\Env\Environment::getInstance()->set('APP_KEY', 'key-used-by-the-tests-only-0123456789abcdef');

// Each test gets a new database : PHP writes the session at shutdown, once the database of the last test is gone
Configuration::getInstance()->add(new SessionConfiguration(driver: new PHPGlobalSession()));
// The tests make their accounts by registering, closed by default
Configuration::getInstance()->add(new RegistrationConfiguration(open: true));

TestContext::useTemporaryStorage();

$testDatabase = TestContext::getInstance()->createEmptyApplicationDatabase();
Database::setInstance($testDatabase);