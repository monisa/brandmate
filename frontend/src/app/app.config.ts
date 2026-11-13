import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, inject, provideAppInitializer, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { routes } from './app.routes';
import { AuthService } from './core/auth/auth.service';
import { I18n } from './core/i18n/i18n';
import { authInterceptor } from './core/interceptors/auth.interceptor';
import { errorInterceptor } from './core/interceptors/error.interceptor';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideHttpClient(withInterceptors([authInterceptor, errorInterceptor])),
    provideRouter(routes, withComponentInputBinding()),
    // Restore the session before the first route is resolved, so guards see the user.
    provideAppInitializer(() => firstValueFrom(inject(AuthService).restore())),
    // Load the saved language before anything is drawn, so there is no flash of English.
    provideAppInitializer(() => inject(I18n).init()),
  ],
};
