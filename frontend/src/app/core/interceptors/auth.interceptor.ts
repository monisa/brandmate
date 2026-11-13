import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { environment } from '../../../environments/environment';
import { TokenStore } from '../auth/token-store';

/** Sends the bearer token, and only to our own API. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const token = inject(TokenStore).token();

  if (!token || !req.url.startsWith(environment.apiUrl)) {
    return next(req);
  }

  return next(req.clone({ setHeaders: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }));
};
