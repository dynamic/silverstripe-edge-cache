<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;

/**
 * A member subclass a page can list (an author).
 */
class AuthorMember extends Member implements TestOnly
{
    private static $table_name = 'EdgeCacheAuthorMember';

    private static $db = ['Bio' => 'Varchar'];
}
