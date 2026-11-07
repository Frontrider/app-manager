<?php

use App\Filters\LdapSession;
use App\Libraries\LdapAuth;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Ldap;

final class LdapAuthTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        session()->remove('ldap_user');
        $this->withSession([]);
    }

    public function testAnonymousVisitorsAreRedirectedToLogin(): void
    {
        $result = $this->get('/');
        $result->assertRedirectTo(site_url('login'));
    }

    public function testLoginPageIsPublic(): void
    {
        $result = $this->get('login');
        $result->assertStatus(200);
        $this->assertStringContainsString('Sign in with LDAP', $result->response()->getBody());
    }

    public function testOrdinaryUsersCannotManageApplications(): void
    {
        session()->set('ldap_user', $this->user(false));
        $filter = new LdapSession();
        $response = $filter->before(service('request'), ['admin']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('lldap_admin', $response->getBody());
        $this->assertNull($filter->before(service('request')));
    }

    public function testAdministratorCanPassManagementFilter(): void
    {
        session()->set('ldap_user', $this->user(true));
        $this->assertNull((new LdapSession())->before(service('request'), ['admin']));
    }

    public function testManagementRoutesRejectOrdinaryUsersBeforeDockerActions(): void
    {
        foreach (['install', 'uninstall'] as $action) {
            $this->withSession(['ldap_user' => $this->user(false)])
                ->post('apps/nonexistent/' . $action, [csrf_token() => csrf_hash()])
                ->assertStatus(403);
        }
    }

    public function testLogoutClearsAuthentication(): void
    {
        $result = $this->withSession(['ldap_user' => $this->user(true)])
            ->post('logout', [csrf_token() => csrf_hash()]);
        $result->assertRedirectTo(site_url('login'));
        $this->assertNull(session()->get('ldap_user'));
    }

    public function testExpiredSessionCannotManageApplications(): void
    {
        $user = $this->user(true);
        $user['expiresAt'] = time() - 1;
        session()->set('ldap_user', $user);
        $response = (new LdapSession())->before(service('request'), ['admin']);
        $this->assertSame(site_url('login'), $response->getHeaderLine('Location'));
        $this->assertNull(session()->get('ldap_user'));
    }

    public function testSuccessfulLoginStoresProviderIdentity(): void
    {
        $provider = $this->createMock(LdapAuth::class);
        $provider->expects($this->once())->method('authenticate')
            ->with('reader', 'secret-password')->willReturn($this->user(false));
        Services::injectMock('ldapAuth', $provider);

        $result = $this->post('login', $this->credentials());
        $result->assertRedirectTo(site_url('/'));
        $this->assertSame(false, session()->get('ldap_user')['isAdmin']);
        $this->assertArrayNotHasKey('password', session()->get('ldap_user'));
    }

    public function testInvalidCredentialsAreRejected(): void
    {
        $provider = $this->createMock(LdapAuth::class);
        $provider->method('authenticate')->willReturn(null);
        Services::injectMock('ldapAuth', $provider);
        $result = $this->post('login', $this->credentials());
        $result->assertStatus(401);
        $this->assertNull(session()->get('ldap_user'));
        $this->assertStringContainsString('Invalid username or password', $result->response()->getBody());
    }

    public function testProviderFailureDoesNotCreateAuthenticatedSession(): void
    {
        $provider = $this->createMock(LdapAuth::class);
        $provider->method('authenticate')->willThrowException(new RuntimeException('Provider unavailable.'));
        Services::injectMock('ldapAuth', $provider);
        $this->post('login', $this->credentials())->assertStatus(503);
        $this->assertNull(session()->get('ldap_user'));
    }

    public function testEmptyPasswordCannotPerformAnonymousBind(): void
    {
        $this->assertNull((new LdapAuth(new Ldap()))->authenticate('reader', ''));
    }

    public function testDefaultsUseLldapWithoutSearchAccountCredentials(): void
    {
        $config = new Ldap();
        $this->assertSame('ldap://lldap:3890', $config->uri);
        $this->assertSame('ou=people,dc=example,dc=com', $config->userBaseDN);
        $this->assertSame('lldap_admin', $config->adminGroup);
        $this->assertFalse(property_exists($config, 'bindDN'));
        $this->assertFalse(property_exists($config, 'bindPassword'));
    }

    public function testCardManagementControlsAreRestrictedToAdministrators(): void
    {
        $app = [
            'id' => 'test', 'name' => 'Example', 'path' => 'compose.yaml',
            'website' => 'https://example.org/', 'caddyPath' => '/example/',
        ];
        foreach ([false, true] as $running) {
            $reader = view('partials/app_card', ['app' => $app, 'isRunning' => $running, 'canManage' => false]);
            $admin = view('partials/app_card', ['app' => $app, 'isRunning' => $running, 'canManage' => true]);
            $this->assertStringNotContainsString('<form', $reader);
            $this->assertStringContainsString('<form', $admin);
        }
    }

    private function user(bool $isAdmin): array
    {
        return ['username' => 'reader', 'isAdmin' => $isAdmin, 'expiresAt' => time() + 3600];
    }

    private function credentials(): array
    {
        return [
            'username' => 'reader',
            'password' => 'secret-password',
            csrf_token() => csrf_hash(),
        ];
    }
}
