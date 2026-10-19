<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use PageController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\TestOnly;

/**
 * A page controller that renders a fixed body instead of the install's theme.
 *
 * What a theme adds to a page is not what a test of the response headers is about, and some of it
 * writes to the session: the CMS navigator a theme shows a logged-in editor builds its logout link
 * with a CSRF token (`Security::logout_url()`), and core answers a request whose session holds
 * anything with `private`. The test logs out as well, so the page is rendered as the anonymous
 * visitor the edge serves; this controller keeps the body the same whatever the theme would add.
 */
class BarePageController extends PageController implements TestOnly
{
    public function index(HTTPRequest $request): string
    {
        return '<!DOCTYPE html><html><body>' . htmlspecialchars((string) $this->data()->Title) . '</body></html>';
    }
}
