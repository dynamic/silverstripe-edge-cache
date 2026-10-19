<?php

namespace Dynamic\EdgeCache\Tests\Fixtures;

use PageController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\TestOnly;

/**
 * A page controller that renders a fixed body instead of the install's theme.
 *
 * A theme can put a form on every page (the startup theme's layout renders `$Form`), and a form's
 * CSRF token is written to the session, which makes core answer `private`. A test of the response
 * headers wants the page as the module sees it, with nothing the theme adds.
 */
class BarePageController extends PageController implements TestOnly
{
    public function index(HTTPRequest $request): string
    {
        return '<!DOCTYPE html><html><body>' . htmlspecialchars((string) $this->data()->Title) . '</body></html>';
    }
}
