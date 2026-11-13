import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../../environments/environment';
import { TokenStore } from '../auth/token-store';
import { authInterceptor } from './auth.interceptor';

describe('authInterceptor', () => {
  let http: HttpClient;
  let backend: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(withInterceptors([authInterceptor])), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpClient);
    backend = TestBed.inject(HttpTestingController);
    TestBed.inject(TokenStore).set('abc');
  });

  afterEach(() => backend.verify());

  it('sends the bearer token to the API', () => {
    http.get(`${environment.apiUrl}/company`).subscribe();
    const req = backend.expectOne(`${environment.apiUrl}/company`);
    expect(req.request.headers.get('Authorization')).toBe('Bearer abc');
    req.flush({});
  });

  it('never sends the token to other hosts', () => {
    http.get('https://example.org/data.json').subscribe();
    const req = backend.expectOne('https://example.org/data.json');
    expect(req.request.headers.has('Authorization')).toBe(false);
    req.flush({});
  });
});
