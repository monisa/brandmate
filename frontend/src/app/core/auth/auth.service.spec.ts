import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { environment } from '../../../environments/environment';
import { fakeUser } from '../testing';
import { AuthService } from './auth.service';
import { TokenStore } from './token-store';

describe('AuthService', () => {
  let auth: AuthService;
  let http: HttpTestingController;
  let tokens: TokenStore;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    auth = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
    tokens = TestBed.inject(TokenStore);
  });

  afterEach(() => http.verify());

  it('stores the token and user after signing in', () => {
    auth.login('asha@example.com', 'secret-pass1').subscribe();

    const req = http.expectOne(`${environment.apiUrl}/auth/login`);
    expect(req.request.body).toEqual({ email: 'asha@example.com', password: 'secret-pass1' });
    req.flush({ data: { token: 'abc', expires_at: null, user: fakeUser() } });

    expect(tokens.token()).toBe('abc');
    expect(auth.isSignedIn()).toBe(true);
    expect(auth.can('team.manage')).toBe(true);
    expect(auth.can('billing.manage')).toBe(false);
  });

  it('does not call the API on startup without a stored token', () => {
    let result: unknown = 'pending';
    auth.restore().subscribe((user) => (result = user));

    http.expectNone(`${environment.apiUrl}/auth/me`);
    expect(result).toBeNull();
  });

  it('forgets a stored token the API no longer accepts', () => {
    tokens.set('stale');
    auth.restore().subscribe();

    http.expectOne(`${environment.apiUrl}/auth/me`).flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

    expect(tokens.token()).toBeNull();
    expect(auth.isSignedIn()).toBe(false);
  });

  it('lets a super admin pass every permission check', () => {
    auth.setUser(fakeUser({ is_super_admin: true, permissions: [] }));
    expect(auth.can('anything.at.all')).toBe(true);
  });
});
