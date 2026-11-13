import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AuthService } from '../auth/auth.service';

/** When the API says the session is gone, sign out locally and ask to sign in again. */
export const errorInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  return next(req).pipe(
    catchError((err: unknown) => {
      const ours = req.url.startsWith(environment.apiUrl);
      const signingIn = req.url.includes('/auth/login') || req.url.includes('/auth/me');

      if (ours && !signingIn && err instanceof HttpErrorResponse && err.status === 401) {
        auth.clear();
        void router.navigate(['/login'], { queryParams: { expired: 1 } });
      }

      return throwError(() => err);
    }),
  );
};
