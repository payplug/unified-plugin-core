<?php

declare(strict_types=1);

$autoloadPath = isset($argv[1]) ? $argv[1] : __DIR__ . '/../vendor/autoload.php';

require $autoloadPath;

use PayplugUnifiedCore\Exceptions\InvalidPhoneNumberException;
use PayplugUnifiedCore\Utilities\Helpers\AmountHelper;
use PayplugUnifiedCore\Utilities\Helpers\PhoneHelper;

$failures = array();

function check($label, $assertion, array &$failures)
{
    try {
        if ($assertion() !== true) {
            $failures[] = $label . ': assertion returned false';
        }
    } catch (\Throwable $e) {
        $failures[] = $label . ': ' . get_class($e) . ' - ' . $e->getMessage();
    }
}

check('AmountHelper::toCents', function () {
    return AmountHelper::toCents(19.99) === 1999;
}, $failures);

check('PhoneHelper::toE164', function () {
    return PhoneHelper::toE164('0612345678', 'FR') === '+33612345678';
}, $failures);

check('PhoneHelper::isMobile true', function () {
    return PhoneHelper::isMobile('0612345678', 'FR') === true;
}, $failures);

check('PhoneHelper::isMobile false', function () {
    return PhoneHelper::isMobile('0123456789', 'FR') === false;
}, $failures);

check('PhoneHelper throws on invalid number', function () {
    try {
        PhoneHelper::toE164('not a phone number', 'FR');

        return false;
    } catch (InvalidPhoneNumberException $e) {
        return true;
    }
}, $failures);

if (count($failures) > 0) {
    fwrite(STDERR, "PHP 7.1 smoke check FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo 'PHP 7.1 smoke check passed (running ' . PHP_VERSION . ")\n";
exit(0);
