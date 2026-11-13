import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, Router, RouterStateSnapshot, UrlTree, provideRouter } from '@angular/router';
import { AuthService } from '../auth/auth.service';
import { fakeUser } from '../testing';
import { authGuard, brandHomeGuard, companyGuard, creatorGuard, permissionGuard, superAdminGuard, verifiedGuard } from './guards';

describe('route guards', () => {
  let auth: AuthService;
  let router: Router;
  const route = {} as ActivatedRouteSnapshot;
  const state = { url: '/team' } as RouterStateSnapshot;

  const run = (guard: typeof authGuard) => TestBed.runInInjectionContext(() => guard(route, state));
  const urlOf = (result: unknown) => router.serializeUrl(result as UrlTree);

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    auth = TestBed.inject(AuthService);
    router = TestBed.inject(Router);
  });

  it('sends signed-out visitors to sign in and remembers where they were going', () => {
    expect(urlOf(run(authGuard))).toBe('/login?returnUrl=%2Fteam');
  });

  it('lets signed-in users through', () => {
    auth.setUser(fakeUser());
    expect(run(authGuard)).toBe(true);
  });

  it('holds users with an unconfirmed email on the confirmation page', () => {
    auth.setUser(fakeUser({ email_verified: false }));
    expect(urlOf(run(verifiedGuard))).toBe('/verify-email');
  });

  it('keeps company users out of the platform admin area', () => {
    auth.setUser(fakeUser());
    expect(urlOf(run(superAdminGuard))).toBe('/dashboard');
  });

  it('sends creators to their own workspace instead of brand pages', () => {
    auth.setUser(fakeUser({ account_type: 'creator', company: null, role: null, permissions: [] }));
    expect(run(creatorGuard)).toBe(true);
    expect(urlOf(run(brandHomeGuard))).toBe('/creator');
    expect(urlOf(run(companyGuard))).toBe('/creator');
  });

  it('keeps brand users out of the creator workspace', () => {
    auth.setUser(fakeUser());
    expect(urlOf(run(creatorGuard))).toBe('/dashboard');
    expect(run(brandHomeGuard)).toBe(true);
  });

  it('checks a named permission', () => {
    auth.setUser(fakeUser({ permissions: ['team.manage'] }));
    expect(run(permissionGuard('team.manage'))).toBe(true);
    expect(urlOf(run(permissionGuard('billing.manage')))).toBe('/dashboard');
  });
});
