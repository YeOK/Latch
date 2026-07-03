<?php

declare(strict_types=1);

use Latch\Core\Config;
use Latch\Core\InputValidator;
use PHPUnit\Framework\TestCase;

final class InputValidatorTest extends TestCase
{
    private InputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InputValidator(new Config(dirname(__DIR__) . '/config'));
    }

    public function testUsernameRejectsInvalidCharacters(): void
    {
        $this->assertNotNull($this->validator->usernameError('_bad'));
        $this->assertNotNull($this->validator->usernameError('has space'));
        $this->assertNull($this->validator->usernameError('good_user-1'));
    }

    public function testPostBodyEnforcesMaxLength(): void
    {
        $this->assertNull($this->validator->postBodyError('hello'));
        $this->assertNotNull($this->validator->postBodyError(str_repeat('a', 65536)));
    }

    public function testTopicTitleRange(): void
    {
        $this->assertNotNull($this->validator->topicTitleError(''));
        $this->assertNull($this->validator->topicTitleError(str_repeat('x', 255)));
        $this->assertNotNull($this->validator->topicTitleError(str_repeat('x', 256)));
    }

    public function testBioRejectsOverflow(): void
    {
        $this->assertNull($this->validator->bioError(str_repeat('b', 500)));
        $this->assertNotNull($this->validator->bioError(str_repeat('b', 501)));
    }

    public function testPasswordMaxLength(): void
    {
        $this->assertNull($this->validator->passwordError(str_repeat('p', 128)));
        $this->assertNotNull($this->validator->passwordError(str_repeat('p', 129)));
    }
}