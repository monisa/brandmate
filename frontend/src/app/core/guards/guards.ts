import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../auth/auth.service';

// Guards only improve navigation. They are not security: the API checks
// authentication, tenant and permissions on every request.

/** Signed-in users only; everyone else goes to the sign-in page. */
export const authGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthService);
  return auth.isSignedIn() ? true : inject(Router).createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
};

/** Signed-in users with a confirmed e-mail address. */
export const verifiedGuard: CanActivateFn = () => {
  const user = inject(AuthService).user();
  return user?.email_verified ? true : inject(Router).createUrlTree(['/verify-email']);
};

/** Keeps signed-in users away from the sign-in and registration pages. */
export const guestGuard: CanActivateFn = () => {
  return inject(AuthService).isSignedIn() ? inject(Router).createUrlTree(['/']) : true;
};

/** Pages that need a company workspace (a Super Admin may not have one). */
export const companyGuard: CanActivateFn = () => {
  const user = inject(AuthService).user();
  if (user?.company) {
    return true;
  }
  return inject(Router).createUrlTree([user?.account_type === 'creator' ? '/creator' : '/admin']);
};

/** Creator accounts only. */
export const creatorGuard: CanActivateFn = () => {
  return inject(AuthService).user()?.account_type === 'creator' ? true : inject(Router).createUrlTree(['/dashboard']);
};

/** The brand dashboard is not a creator's home page. */
export const brandHomeGuard: CanActivateFn = () => {
  return inject(AuthService).user()?.account_type === 'creator' ? inject(Router).createUrlTree(['/creator']) : true;
};

export const superAdminGuard: CanActivateFn = () => {
  return inject(AuthService).user()?.is_super_admin ? true : inject(Router).createUrlTree(['/dashboard']);
};

export function permissionGuard(permission: string): CanActivateFn {
  return () => (inject(AuthService).can(permission) ? true : inject(Router).createUrlTree(['/dashboard']));
}
