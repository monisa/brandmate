import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, catchError, finalize, map, of, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { Item, Session, User } from '../models';
import { TokenStore } from './token-store';

export interface RegisterPayload {
  name: string;
  email: string;
  company_name: string;
  password: string;
  password_confirmation: string;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly tokens = inject(TokenStore);
  private readonly api = environment.apiUrl;

  private readonly currentUser = signal<User | null>(null);

  readonly user = this.currentUser.asReadonly();
  readonly isSignedIn = computed(() => this.currentUser() !== null);

  /** Called once at startup: restores the session from a stored token. */
  restore(): Observable<User | null> {
    if (!this.tokens.token()) {
      return of(null);
    }
    return this.refresh().pipe(
      catchError(() => {
        this.clear();
        return of(null);
      }),
    );
  }

  refresh(): Observable<User> {
    return this.http.get<Item<User>>(`${this.api}/auth/me`).pipe(
      map((res) => res.data),
      tap((user) => this.currentUser.set(user)),
    );
  }

  login(email: string, password: string): Observable<User> {
    return this.start(this.http.post<Item<Session>>(`${this.api}/auth/login`, { email, password }));
  }

  /** Finishes "Continue with Google / Facebook": trades the one-time code for a session. */
  exchangeOAuthCode(code: string): Observable<User> {
    return this.start(this.http.post<Item<Session>>(`${this.api}/auth/oauth/exchange`, { code }));
  }

  /** Full-page address that starts sign-in with a provider (the browser leaves the app). */
  oauthUrl(provider: 'google' | 'facebook'): string {
    return `${this.api}/auth/oauth/${provider}`;
  }

  register(payload: RegisterPayload): Observable<User> {
    return this.start(this.http.post<Item<Session>>(`${this.api}/auth/register`, payload));
  }

  registerCreator(payload: Omit<RegisterPayload, 'company_name'>): Observable<User> {
    return this.start(this.http.post<Item<Session>>(`${this.api}/auth/register-creator`, payload));
  }

  acceptInvitation(token: string, payload: { name: string; password: string; password_confirmation: string }): Observable<User> {
    return this.start(
      this.http.post<Item<Session>>(`${this.api}/invitations/${encodeURIComponent(token)}/accept`, payload),
    );
  }

  logout(): void {
    this.http
      .post(`${this.api}/auth/logout`, {})
      .pipe(
        catchError(() => of(null)),
        finalize(() => {
          this.clear();
          void this.router.navigate(['/login']);
        }),
      )
      .subscribe();
  }

  /** Forgets the session locally (expired token, 401 from the API). */
  clear(): void {
    this.tokens.clear();
    this.currentUser.set(null);
  }

  setUser(user: User): void {
    this.currentUser.set(user);
  }

  can(permission: string): boolean {
    const user = this.currentUser();
    return !!user && (user.is_super_admin || user.permissions.includes(permission));
  }

  private start(request: Observable<Item<Session>>): Observable<User> {
    return request.pipe(
      tap((res) => {
        this.tokens.set(res.data.token);
        this.currentUser.set(res.data.user);
      }),
      map((res) => res.data.user),
    );
  }
}
