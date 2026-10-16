<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class LibraryValidationTest extends TestCaseSymconValidation
{
    // Prüft library.json, module.json, form.json und locale.json der Bibliothek mit dem Validator der Symcon-Stubs
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateCloud(): void
    {
        $this->validateModule(__DIR__ . '/../MammotionCloud');
    }

    public function testValidateConfigurator(): void
    {
        $this->validateModule(__DIR__ . '/../MammotionConfigurator');
    }

    public function testValidateMower(): void
    {
        $this->validateModule(__DIR__ . '/../MammotionMower');
    }

    public function testValidateRtk(): void
    {
        $this->validateModule(__DIR__ . '/../MammotionRtk');
    }
}
