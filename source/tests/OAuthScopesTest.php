<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\OAuthScopes;
use PHPUnit\Framework\TestCase;

final class OAuthScopesTest extends TestCase
{
    public function testParseScopeStringDefaultsToRead(): void
    {
        $this->assertSame([OAuthScopes::READ], OAuthScopes::parseScopeString(''));
    }

    public function testNormalizeDropsUnknownScopes(): void
    {
        $this->assertSame(
            [OAuthScopes::READ],
            OAuthScopes::normalize(['read', 'write', 'messages:admin', '']),
        );
    }

    public function testNormalizeIncludesMessageScopes(): void
    {
        $this->assertSame(
            [OAuthScopes::READ, OAuthScopes::MESSAGES_READ, OAuthScopes::MESSAGES_WRITE],
            OAuthScopes::normalize([
                OAuthScopes::READ,
                OAuthScopes::MESSAGES_READ,
                OAuthScopes::MESSAGES_WRITE,
            ]),
        );
    }

    public function testIntersectHonorsClientGrants(): void
    {
        $this->assertSame(
            [OAuthScopes::READ],
            OAuthScopes::intersect([OAuthScopes::READ], [OAuthScopes::READ, 'write']),
        );
        $this->assertSame([], OAuthScopes::intersect([OAuthScopes::READ], ['write']));
    }

    public function testFilterForClientCredentialsStripsMessageScopes(): void
    {
        $this->assertSame(
            [OAuthScopes::READ],
            OAuthScopes::filterForClientCredentials([
                OAuthScopes::READ,
                OAuthScopes::MESSAGES_READ,
                OAuthScopes::MESSAGES_WRITE,
            ]),
        );
        $this->assertSame(
            [],
            OAuthScopes::filterForClientCredentials([OAuthScopes::MESSAGES_READ]),
        );
    }

    public function testIsUserDelegatedOnly(): void
    {
        $this->assertTrue(OAuthScopes::isUserDelegatedOnly(OAuthScopes::MESSAGES_READ));
        $this->assertTrue(OAuthScopes::isUserDelegatedOnly(OAuthScopes::MESSAGES_WRITE));
        $this->assertFalse(OAuthScopes::isUserDelegatedOnly(OAuthScopes::READ));
    }
}