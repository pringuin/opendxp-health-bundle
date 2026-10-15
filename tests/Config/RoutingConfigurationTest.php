<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The admin routes rely on OpenDXP's CsrfProtectionListener, which only runs for requests in the
 * admin context. OpenDXP resolves that context from the path, so moving the routes out of /admin
 * would silently drop the CSRF protection of the POST routes.
 */
final class RoutingConfigurationTest extends TestCase
{
    /** Path pattern of the admin context in open-dxp/admin-bundle (config/opendxp/default.yaml). */
    private const string ADMIN_CONTEXT_PATH_PATTERN = '#^/admin(/.*)?$#';

    public function testAdminRoutesStayInTheCsrfProtectedAdminContext(): void
    {
        $routing = Yaml::parseFile(__DIR__ . '/../../config/opendxp/routing.yaml');

        self::assertMatchesRegularExpression(
            self::ADMIN_CONTEXT_PATH_PATTERN,
            $routing['_pringuin_opendxp_health_admin']['prefix']
        );
    }

    public function testThePublicStatusRouteIsOutsideTheAdminContext(): void
    {
        $routing = Yaml::parseFile(__DIR__ . '/../../config/opendxp/routing.yaml');

        // the status endpoint is used by external monitors without an admin session or CSRF token
        self::assertDoesNotMatchRegularExpression(
            self::ADMIN_CONTEXT_PATH_PATTERN,
            $routing['_pringuin_opendxp_health_status']['prefix']
        );
    }
}
